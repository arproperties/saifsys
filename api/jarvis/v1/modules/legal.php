<?php
/**
 * Jarvis API v1 — the Legal module: a lease as a lawyer needs to see it.
 *
 * Loaded only by ../index.php, after the key has been checked; opened directly it
 * answers nothing. $conn, $action and the jarvis_api_* helpers come from there.
 *
 * View only: every action reads, none writes. Reem's Legal cases link each case to its
 * lease here, show the lease file on the case, and check it (rent owed, bounced cheques,
 * Ejari, notices) so the lawyer sees what is wrong and what is missing.
 *
 *   ?module=legal&action=leases&q=ali
 *        up to 30 leases (any status but draft) whose lease number, tenant, building or
 *        unit matches q (2+ characters), newest first, for picking the lease of a case.
 *
 *   ?module=legal&action=lease&id=123
 *        one lease's whole file: the lease and its terms, the landlord company, the tenant's
 *        contacts, what is owed (saifsys's own lease financial summary, the same one the
 *        tenant app shows) with the open charges and what of it is overdue, the payments,
 *        the cheques (bounced ones with their reason), the security deposit, Ejari,
 *        violations and complaints, move-out notices, an open renewal, the tenant's other
 *        leases, and what saifsys's own legal desk has on the lease: its cases, notices,
 *        hearings, costs and cheque escalations.
 *
 *   ?module=legal&action=at_risk&days=30
 *        leases that may need a legal case: a cheque bounced or escalated to legal and not
 *        replaced, or rent overdue more than `days` (1-365). Up to 40, worst first.
 *
 * Left out on purpose: ID, passport and visa numbers, bank account details, documents.
 * Money comes from modules/realestate/includes/lease_financial_summary_service.php and
 * the deposit from security_deposit_helper.php, so the figures are the screens' figures.
 */

declare(strict_types=1);

if (!defined('JARVIS_API')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../../../modules/realestate/includes/lease_financial_summary_service.php';

$today = (new DateTime('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');

/** Whether a table is there: the legal desk's tables are not on every saifsys. */
function jarvis_legal_has(PDO $conn, string $table): bool
{
    static $seen = [];
    if (!array_key_exists($table, $seen)) {
        $st = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        $st->execute([$table]);
        $seen[$table] = (bool)$st->fetchColumn();
    }
    return $seen[$table];
}

function jarvis_legal_money($v): float
{
    return round((float)($v ?? 0), 2);
}

function jarvis_legal_tenant_name(array $r): string
{
    $company = trim((string)($r['company_name'] ?? ''));
    return $company !== '' ? $company : trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
}

function jarvis_legal_all(PDO $conn, string $sql, array $params = []): array
{
    $st = $conn->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** In-list placeholders for ids, never empty. */
function jarvis_legal_in(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        $ids = [0];
    }
    return [implode(',', array_fill(0, count($ids), '?')), $ids];
}

/** What is owed, from saifsys's own summary, and the part of it already past its due date. */
function jarvis_legal_money_of(PDO $conn, int $companyId, int $leaseId, string $today): array
{
    $s = re_lease_financial_summary($conn, $companyId, $leaseId);
    $open = re_lease_outstanding_items($conn, $companyId, $leaseId, ['open_only' => true, 'limit' => 200]);
    $overdue = 0.0;
    $count = 0;
    $oldest = null;
    $items = [];
    foreach ($open['items'] ?? [] as $it) {
        $out = (float)($it['outstanding_amount'] ?? 0);
        if ($out <= 0.005) {
            continue;
        }
        $due = (string)($it['due_date'] ?? '');
        if ($due !== '' && $due < $today) {
            $overdue += $out;
            $count++;
            if ($oldest === null || $due < $oldest) {
                $oldest = $due;
            }
        }
        if (count($items) < 40) {
            $items[] = [
                'number' => (string)($it['document_number'] ?? ''),
                'kind' => (string)($it['class'] ?? ''),
                'description' => (string)($it['description'] ?? ''),
                'due_date' => $due,
                'total' => jarvis_legal_money($it['total_amount'] ?? 0),
                'paid' => jarvis_legal_money($it['paid_amount'] ?? 0),
                'outstanding' => jarvis_legal_money($out),
            ];
        }
    }
    $last = $s['last_payment'] ?? null;
    return [
        'as_of' => $today,
        'accounting_mode' => (string)($s['accounting_mode'] ?? ($open['accounting_mode'] ?? '')),
        'outstanding' => jarvis_legal_money($s['outstanding_total'] ?? 0),
        'rent_outstanding' => jarvis_legal_money($s['rent_outstanding'] ?? 0),
        'service_charge_outstanding' => jarvis_legal_money($s['service_charge_outstanding'] ?? 0),
        'penalties' => jarvis_legal_money($s['penalty_outstanding'] ?? 0),
        // Worked out here from the open charges: the summary's own overdue figure is the
        // whole outstanding on older (legacy) leases.
        'overdue' => jarvis_legal_money($overdue),
        'overdue_count' => $count,
        'oldest_overdue_date' => $oldest,
        'paid_total' => jarvis_legal_money($s['paid_total'] ?? 0),
        'tenant_credit' => jarvis_legal_money($s['tenant_credit'] ?? 0),
        'last_payment' => is_array($last) ? $last : null,
        'open_charges' => $items,
    ];
}

// ---------- leases: find one ----------

if ($action === 'leases') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        jarvis_api_error('bad_request', 'Type at least 2 characters to search leases.', 400);
    }
    $like = '%' . $q . '%';
    $rows = jarvis_legal_all($conn, "
        SELECT l.id, l.company_id, l.lease_number, l.status, l.start_date, l.end_date,
               b.name AS building, u.unit_number AS unit,
               t.first_name, t.last_name, t.company_name
        FROM re_leases l
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        WHERE l.status <> 'draft'
          AND (l.lease_number LIKE ? OR u.unit_number LIKE ? OR b.name LIKE ? OR t.company_name LIKE ?
               OR CONCAT(t.first_name, ' ', t.last_name) LIKE ? OR CONCAT(b.name, ' ', u.unit_number) LIKE ?)
        ORDER BY (l.status = 'active') DESC, l.end_date DESC
        LIMIT 30", [$like, $like, $like, $like, $like, $like]);
    jarvis_api_send(['ok' => true, 'leases' => array_map(static fn($r) => [
        'lease_id' => (int)$r['id'],
        'company_id' => (int)$r['company_id'],
        'lease_number' => (string)($r['lease_number'] ?? ''),
        'status' => (string)$r['status'],
        'start_date' => (string)$r['start_date'],
        'end_date' => (string)$r['end_date'],
        'building' => (string)$r['building'],
        'unit' => (string)$r['unit'],
        'tenant' => jarvis_legal_tenant_name($r),
    ], $rows)]);
}

// ---------- lease: the whole file ----------

if ($action === 'lease') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $conn->prepare("
        SELECT l.*, b.name AS building, u.unit_number AS unit, u.unit_type, c.name AS landlord,
               t.id AS t_id, t.tenant_type, t.first_name, t.last_name, t.company_name, t.email AS t_email,
               t.phone AS t_phone, t.phone_alt AS t_phone_alt, t.id_type, t.visa_expiry_date
        FROM re_leases l
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        LEFT JOIN companies c ON c.id = l.company_id
        WHERE l.id = ?");
    $st->execute([$id]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) {
        jarvis_api_error('not_found', 'No lease with that id in saifsys.', 404);
    }
    $companyId = (int)$l['company_id'];
    $tenantId = (int)$l['t_id'];

    $cheques = jarvis_legal_all($conn, "
        SELECT id, cheque_number, bank_name, cheque_date, cheque_amount, status, deposited_date, cleared_date, bounced_date, bounced_reason
        FROM re_post_dated_cheques WHERE lease_id = ? ORDER BY cheque_date, id LIMIT 80", [$id]);
    $chequeIds = array_map(static fn($c) => (int)$c['id'], $cheques);

    $sd = null;
    if (function_exists('re_sd_summary')) {
        try {
            $d = re_sd_summary($conn, $companyId, $id);
            $sd = [
                'amount' => jarvis_legal_money($d['expected'] ?? $l['security_deposit']),
                'received' => jarvis_legal_money($d['received'] ?? 0),
                'refundable' => jarvis_legal_money($d['refundable'] ?? 0),
                'deductions' => jarvis_legal_money($d['deductions'] ?? 0),
                'status' => (string)($d['status'] ?? ''),
                'settled_at' => in_array((string)($d['status'] ?? ''), ['refunded', 'deducted'], true) ? 'yes' : null,
            ];
        } catch (Throwable $e) {
            $sd = null;
        }
    }
    if ($sd === null) {
        $sd = ['amount' => jarvis_legal_money($l['security_deposit']), 'received' => null, 'refundable' => null, 'deductions' => null, 'status' => '', 'settled_at' => null];
    }

    $ejari = null;
    if (jarvis_legal_has($conn, 're_ejari_tracking')) {
        $e = jarvis_legal_all($conn, "SELECT ejari_number, registration_date, expiry_date, registration_status
            FROM re_ejari_tracking WHERE lease_id = ? ORDER BY id DESC LIMIT 1", [$id])[0] ?? null;
        if ($e) {
            $ejari = ['number' => (string)$e['ejari_number'], 'registration_date' => $e['registration_date'], 'expiry_date' => $e['expiry_date'], 'status' => (string)$e['registration_status']];
        }
    }
    if ($ejari === null && trim((string)($l['ejari_registration_number'] ?? '')) !== '') {
        $ejari = ['number' => (string)$l['ejari_registration_number'], 'registration_date' => $l['ejari_issue_date'], 'expiry_date' => null, 'status' => 'registered'];
    }

    $violations = jarvis_legal_has($conn, 're_tenant_issues_violations') ? jarvis_legal_all($conn, "
        SELECT reported_date, issue_type, severity, title, description, status, resolved_date
        FROM re_tenant_issues_violations WHERE lease_id = ? OR (lease_id IS NULL AND tenant_id = ?)
        ORDER BY reported_date DESC LIMIT 20", [$id, $tenantId]) : [];

    $moveOuts = jarvis_legal_has($conn, 're_move_out_notices') ? jarvis_legal_all($conn, "
        SELECT notice_date, intended_move_out_date, notice_type, notice_reason, status
        FROM re_move_out_notices WHERE lease_id = ? ORDER BY notice_date DESC LIMIT 5", [$id]) : [];

    $renewal = null;
    if (jarvis_legal_has($conn, 're_lease_renewal_workflows')) {
        $renewal = jarvis_legal_all($conn, "SELECT status FROM re_lease_renewal_workflows
            WHERE lease_id = ? AND status NOT IN ('rejected', 'cancelled', 'completed', 'converted') ORDER BY id DESC LIMIT 1", [$id])[0]['status'] ?? null;
    }

    $others = jarvis_legal_all($conn, "
        SELECT l.id, l.lease_number, l.status, l.end_date, b.name AS building, u.unit_number AS unit
        FROM re_leases l JOIN re_units u ON u.id = l.unit_id JOIN re_buildings b ON b.id = u.building_id
        WHERE l.tenant_id = ? AND l.id <> ? AND l.status IN ('active', 'has_legal_case', 'expired')
        ORDER BY l.end_date DESC LIMIT 10", [$tenantId, $id]);

    $payments = re_lease_payment_history($conn, $companyId, $id, 30)['receipts'] ?? [];

    // saifsys's own legal desk on this lease.
    $legal = ['cases' => [], 'notices' => [], 'hearings' => [], 'escalations' => []];
    if (jarvis_legal_has($conn, 're_legal_cases')) {
        [$inCheques, $chequeParams] = jarvis_legal_in($chequeIds);
        $linked = jarvis_legal_has($conn, 're_legal_case_links')
            ? " OR id IN (SELECT case_id FROM re_legal_case_links WHERE link_type = 'lease' AND link_id = ?)" : '';
        $cases = jarvis_legal_all($conn, "
            SELECT id, case_number, title, case_type, status, claim_amount, recovered_amount, external_reference,
                   jurisdiction, opened_date, filed_date, closed_date, next_action_date, deadline_date
            FROM re_legal_cases
            WHERE deleted_at IS NULL AND (lease_id = ? OR primary_cheque_id IN ($inCheques)$linked)
            ORDER BY id DESC LIMIT 10",
            array_merge([$id], $chequeParams, $linked ? [$id] : []));
        $caseIds = array_map(static fn($c) => (int)$c['id'], $cases);
        [$inCases, $caseParams] = jarvis_legal_in($caseIds);
        $costs = [];
        if (jarvis_legal_has($conn, 're_legal_case_costs')) {
            foreach (jarvis_legal_all($conn, "SELECT case_id, SUM(amount) AS total, SUM(CASE WHEN is_recoverable = 1 THEN amount ELSE 0 END) AS recoverable
                FROM re_legal_case_costs WHERE case_id IN ($inCases) GROUP BY case_id", $caseParams) as $r) {
                $costs[(int)$r['case_id']] = $r;
            }
        }
        $legal['cases'] = array_map(static fn($c) => [
            'number' => (string)$c['case_number'],
            'title' => (string)$c['title'],
            'type' => (string)$c['case_type'],
            'status' => (string)$c['status'],
            'claim' => jarvis_legal_money($c['claim_amount']),
            'recovered' => jarvis_legal_money($c['recovered_amount']),
            'court_reference' => (string)($c['external_reference'] ?? ''),
            'jurisdiction' => (string)($c['jurisdiction'] ?? ''),
            'opened' => $c['opened_date'], 'filed' => $c['filed_date'], 'closed' => $c['closed_date'],
            'next_action' => $c['next_action_date'], 'deadline' => $c['deadline_date'],
            'costs' => jarvis_legal_money($costs[(int)$c['id']]['total'] ?? 0),
            'costs_recoverable' => jarvis_legal_money($costs[(int)$c['id']]['recoverable'] ?? 0),
        ], $cases);
        if (jarvis_legal_has($conn, 're_legal_notices')) {
            $legal['notices'] = array_map(static fn($n) => [
                'type' => (string)$n['notice_type'], 'reference' => (string)($n['reference_number'] ?? ''), 'subject' => (string)($n['subject'] ?? ''),
                'issue_date' => $n['issue_date'], 'response_deadline' => $n['response_deadline'],
                'delivery_method' => (string)($n['delivery_method'] ?? ''), 'delivery_status' => (string)($n['delivery_status'] ?? ''), 'delivered_at' => $n['delivered_at'],
            ], jarvis_legal_all($conn, "SELECT notice_type, reference_number, subject, issue_date, response_deadline, delivery_method, delivery_status, delivered_at
                FROM re_legal_notices WHERE lease_id = ? OR case_id IN ($inCases) ORDER BY issue_date DESC, id DESC LIMIT 15", array_merge([$id], $caseParams)));
        }
        if (jarvis_legal_has($conn, 're_legal_hearings')) {
            $legal['hearings'] = array_map(static fn($h) => [
                'type' => (string)$h['event_type'], 'title' => (string)$h['title'], 'date' => $h['scheduled_date'], 'time' => $h['scheduled_time'],
                'location' => (string)($h['location'] ?? ''), 'status' => (string)$h['status'], 'outcome' => (string)($h['outcome'] ?? ''),
            ], jarvis_legal_all($conn, "SELECT event_type, title, scheduled_date, scheduled_time, location, status, outcome
                FROM re_legal_hearings WHERE case_id IN ($inCases) ORDER BY scheduled_date DESC LIMIT 15", $caseParams));
        }
    }
    if (jarvis_legal_has($conn, 're_legal_cheque_escalations')) {
        $legal['escalations'] = array_map(static fn($e) => [
            'type' => (string)$e['type'], 'cheque_number' => (string)$e['cheque_number'], 'amount' => jarvis_legal_money($e['cheque_amount']),
            'cheque_date' => $e['cheque_date'], 'reason' => (string)($e['reason'] ?? ''), 'status' => (string)$e['status'], 'at' => $e['created_at'],
        ], jarvis_legal_all($conn, "SELECT type, cheque_number, cheque_amount, cheque_date, reason, status, created_at
            FROM re_legal_cheque_escalations WHERE lease_id = ? ORDER BY id DESC LIMIT 20", [$id]));
    }

    jarvis_api_send(['ok' => true, 'lease' => [
        'lease' => [
            'id' => $id,
            'company_id' => $companyId,
            'number' => (string)($l['lease_number'] ?? ''),
            'status' => (string)$l['status'],
            'start_date' => (string)$l['start_date'],
            'end_date' => (string)$l['end_date'],
            'annual_rent' => jarvis_legal_money(((float)($l['annual_rent'] ?? 0)) ?: (float)$l['monthly_rent'] * 12),
            'service_charge_monthly' => jarvis_legal_money($l['monthly_service_charge'] ?? 0),
            'security_deposit' => jarvis_legal_money($l['security_deposit']),
            'installments' => (int)($l['number_of_installments'] ?? 0),
            'payment_method' => (string)($l['payment_method'] ?? ''),
            'grace_period_days' => (int)($l['grace_period_days'] ?? 0),
            'penalty_rate_percent' => jarvis_legal_money($l['penalty_rate_percent'] ?? 0),
            'move_in_date' => $l['move_in_date'],
            'move_out_date' => $l['move_out_date'],
            'termination_date' => $l['termination_date'] ?? null,
            'termination_reason' => $l['termination_reason'] ?? null,
            'ejari_number' => (string)($l['ejari_registration_number'] ?? ''),
            'renewal_terms' => (string)($l['renewal_terms'] ?? ''),
            'notes' => mb_substr((string)($l['notes'] ?? ''), 0, 1500),
            'landlord' => (string)($l['landlord'] ?? ''),
        ],
        'building' => (string)$l['building'],
        'unit' => (string)$l['unit'],
        'unit_type' => (string)($l['unit_type'] ?? ''),
        'tenant' => [
            'name' => jarvis_legal_tenant_name($l),
            'type' => (string)($l['tenant_type'] ?? ''),
            'email' => strtolower(trim((string)($l['t_email'] ?? ''))),
            'phone' => (string)($l['t_phone'] ?? ''),
            'phone_alt' => (string)($l['t_phone_alt'] ?? ''),
            'id_type' => (string)($l['id_type'] ?? ''),
            'visa_expiry' => $l['visa_expiry_date'],
        ],
        'money' => jarvis_legal_money_of($conn, $companyId, $id, $today),
        'payments' => array_map(static fn($p) => [
            'date' => $p['payment_date'] ?? null, 'amount' => jarvis_legal_money($p['amount'] ?? 0), 'method' => (string)($p['payment_method'] ?? ''),
            'receipt' => $p['receipt_number'] ?? null, 'reference' => $p['reference_number'] ?? null,
        ], $payments),
        'cheques' => array_map(static fn($c) => [
            'number' => (string)$c['cheque_number'], 'bank' => (string)($c['bank_name'] ?? ''), 'date' => $c['cheque_date'],
            'amount' => jarvis_legal_money($c['cheque_amount']), 'status' => (string)$c['status'],
            'cleared_date' => $c['cleared_date'], 'bounced_date' => $c['bounced_date'], 'bounced_reason' => $c['bounced_reason'],
        ], $cheques),
        'deposit' => $sd,
        'ejari' => $ejari,
        'violations' => array_map(static fn($v) => [
            'date' => $v['reported_date'], 'type' => (string)$v['issue_type'], 'severity' => (string)$v['severity'], 'title' => (string)$v['title'],
            'description' => mb_substr((string)$v['description'], 0, 500), 'status' => (string)$v['status'], 'resolved_date' => $v['resolved_date'],
        ], $violations),
        'move_out_notices' => array_map(static fn($n) => [
            'notice_date' => $n['notice_date'], 'move_out_date' => $n['intended_move_out_date'], 'type' => (string)$n['notice_type'],
            'reason' => (string)($n['notice_reason'] ?? ''), 'status' => (string)$n['status'],
        ], $moveOuts),
        'renewal' => $renewal,
        'other_leases' => array_map(static fn($o) => [
            'lease_id' => (int)$o['id'], 'lease_number' => (string)($o['lease_number'] ?? ''), 'status' => (string)$o['status'],
            'end_date' => $o['end_date'], 'building' => (string)$o['building'], 'unit' => (string)$o['unit'],
        ], $others),
        'saifsys_legal' => $legal,
    ]]);
}

// ---------- at_risk: leases that may need a case ----------

if ($action === 'at_risk') {
    $days = max(1, min(365, (int)($_GET['days'] ?? 30)));
    $cutoff = (new DateTime($today))->modify("-$days days")->format('Y-m-d');

    // Candidates cheaply first: a bounced or escalated cheque, or a charge unpaid since before the cutoff.
    $ids = [];
    foreach (jarvis_legal_all($conn, "SELECT DISTINCT lease_id FROM re_post_dated_cheques WHERE status IN ('bounced', 'legal_escalated') LIMIT 200") as $r) {
        $ids[(int)$r['lease_id']] = true;
    }
    foreach (jarvis_legal_all($conn, "SELECT DISTINCT lease_id FROM re_invoices WHERE status <> 'cancelled' AND outstanding_amount > 0.005 AND due_date < ? LIMIT 300", [$cutoff]) as $r) {
        $ids[(int)$r['lease_id']] = true;
    }
    foreach (jarvis_legal_all($conn, "SELECT DISTINCT lease_id FROM re_lease_installments WHERE status IN ('pending', 'overdue') AND installment_date < ? LIMIT 300", [$cutoff]) as $r) {
        $ids[(int)$r['lease_id']] = true;
    }
    [$in, $params] = jarvis_legal_in(array_keys($ids));
    $leases = jarvis_legal_all($conn, "
        SELECT l.id, l.company_id, l.lease_number, l.status, l.end_date, b.name AS building, u.unit_number AS unit,
               t.first_name, t.last_name, t.company_name,
               (SELECT COUNT(*) FROM re_post_dated_cheques c WHERE c.lease_id = l.id AND c.status IN ('bounced', 'legal_escalated')) AS bounced,
               (SELECT COALESCE(SUM(c.cheque_amount), 0) FROM re_post_dated_cheques c WHERE c.lease_id = l.id AND c.status IN ('bounced', 'legal_escalated')) AS bounced_amount
        FROM re_leases l
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        WHERE l.id IN ($in) AND l.status IN ('active', 'expired', 'terminated', 'has_legal_case')", $params);

    $out = [];
    foreach ($leases as $r) {
        $m = jarvis_legal_money_of($conn, (int)$r['company_id'], (int)$r['id'], $today);
        $late = $m['oldest_overdue_date'] !== null && $m['oldest_overdue_date'] < $cutoff;
        if ((int)$r['bounced'] === 0 && !$late) {
            continue; // paid since, or the old charge was settled by allocation
        }
        $out[] = [
            'lease_id' => (int)$r['id'],
            'lease_number' => (string)($r['lease_number'] ?? ''),
            'status' => (string)$r['status'],
            'end_date' => $r['end_date'],
            'building' => (string)$r['building'],
            'unit' => (string)$r['unit'],
            'tenant' => jarvis_legal_tenant_name($r),
            'bounced' => (int)$r['bounced'],
            'bounced_amount' => jarvis_legal_money($r['bounced_amount']),
            'overdue' => $m['overdue'],
            'oldest_overdue_date' => $m['oldest_overdue_date'],
            'outstanding' => $m['outstanding'],
        ];
    }
    usort($out, static fn($a, $b) => [$b['bounced'] > 0, $b['overdue'] + $b['bounced_amount']] <=> [$a['bounced'] > 0, $a['overdue'] + $a['bounced_amount']]);
    jarvis_api_send(['ok' => true, 'days' => $days, 'leases' => array_slice($out, 0, 40)]);
}

jarvis_api_error('not_found', 'Unknown Legal action.', 404);
