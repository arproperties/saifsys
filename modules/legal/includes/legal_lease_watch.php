<?php
/**
 * Legal — Lease Watch data.
 *
 * One row per lease (same scope as Real Estate → Leases: not deleted, test
 * buildings hidden) with the cheque position the legal team cares about:
 *
 *   bounced  — cheque bounced/returned, not replaced, not settled, not collected
 *   overdue  — cheque date has passed and it is not cleared or fully collected
 *              (no grace period; held-by-finance and legal-escalated cheques
 *              count as overdue too, and also carry their own flag)
 *   due soon — unpaid cheque dated within the next 14 days
 *
 * "Collected" = receipts linked to the cheque (re_payments.cheque_id plus
 * re_receipt_cheque_links when that table exists), as leases.php counts it.
 */

require_once dirname(__DIR__, 2) . '/realestate/includes/lease_lifecycle_guard.php';

const LEGAL_WATCH_DUE_SOON_DAYS = 14;

function legal_lease_watch_rows(PDO $conn, int $companyId): array
{
    $hasLinks = false;
    try {
        $hasLinks = (bool)$conn->query("SHOW TABLES LIKE 're_receipt_cheque_links'")->fetchColumn();
    } catch (Throwable $e) {
        $hasLinks = false;
    }

    $collSql = "SELECT p.cheque_id, p.amount AS amt FROM re_payments p
                WHERE p.company_id = :c1 AND p.cheque_id IS NOT NULL";
    if ($hasLinks) {
        $collSql .= " UNION ALL SELECT k.cheque_id, k.amount_applied AS amt
                      FROM re_receipt_cheque_links k WHERE k.company_id = :c2";
    }

    // Per-cheque open amount, then per-lease aggregates.
    $chequeSql = "
        SELECT c.id, c.lease_id, c.status, c.cheque_date, c.cheque_amount,
               GREATEST(c.cheque_amount - LEAST(COALESCE(col.got, 0), c.cheque_amount), 0) AS open_amt,
               (c.status IN ('cleared','paid','deposited')
                 OR COALESCE(col.got, 0) >= c.cheque_amount - 0.005) AS is_paid,
               (c.replaced_by_cheque_id IS NOT NULL OR c.settlement_payment_id IS NOT NULL) AS is_settled
        FROM re_post_dated_cheques c
        LEFT JOIN (SELECT cheque_id, SUM(amt) AS got FROM ({$collSql}) x GROUP BY cheque_id) col
               ON col.cheque_id = c.id
        WHERE c.company_id = :c3 AND c.status <> 'cancelled'
    ";

    $sql = "
        SELECT l.id, l.lease_number, l.status, l.start_date, l.end_date,
               l.annual_rent, l.contract_amount, l.annual_rent_per_year,
               u.id AS unit_id, u.unit_number, b.id AS building_id, b.name AS building_name,
               t.id AS tenant_id, t.first_name, t.last_name, t.company_name, t.tenant_type, t.phone, t.email,
               COALESCE(a.cheque_count, 0)     AS cheque_count,
               COALESCE(a.face_total, 0)       AS face_total,
               COALESCE(a.collected, 0)        AS collected,
               COALESCE(a.bounced_count, 0)    AS bounced_count,
               COALESCE(a.bounced_amt, 0)      AS bounced_amt,
               COALESCE(a.overdue_count, 0)    AS overdue_count,
               COALESCE(a.overdue_amt, 0)      AS overdue_amt,
               a.oldest_overdue,
               COALESCE(a.held_count, 0)       AS held_count,
               COALESCE(a.escalated_count, 0)  AS escalated_count,
               COALESCE(a.due_soon_count, 0)   AS due_soon_count,
               COALESCE(a.due_soon_amt, 0)     AS due_soon_amt,
               a.next_due
        FROM re_leases l
        JOIN re_units u      ON u.id = l.unit_id
        JOIN re_buildings b  ON b.id = u.building_id
        JOIN re_tenants t    ON t.id = l.tenant_id
        LEFT JOIN (
            SELECT ch.lease_id,
                   COUNT(*) AS cheque_count,
                   SUM(ch.cheque_amount) AS face_total,
                   SUM(ch.cheque_amount - ch.open_amt) AS collected,
                   SUM(ch.status IN ('bounced','returned') AND NOT ch.is_paid AND NOT ch.is_settled) AS bounced_count,
                   SUM(CASE WHEN ch.status IN ('bounced','returned') AND NOT ch.is_paid AND NOT ch.is_settled
                            THEN ch.open_amt ELSE 0 END) AS bounced_amt,
                   SUM(ch.status NOT IN ('bounced','returned') AND NOT ch.is_paid
                       AND ch.cheque_date < CURDATE()) AS overdue_count,
                   SUM(CASE WHEN ch.status NOT IN ('bounced','returned') AND NOT ch.is_paid
                             AND ch.cheque_date < CURDATE() THEN ch.open_amt ELSE 0 END) AS overdue_amt,
                   MIN(CASE WHEN ch.status NOT IN ('bounced','returned') AND NOT ch.is_paid
                             AND ch.cheque_date < CURDATE() THEN ch.cheque_date END) AS oldest_overdue,
                   SUM(ch.status = 'held_by_finance' AND NOT ch.is_paid) AS held_count,
                   SUM(ch.status = 'legal_escalated' AND NOT ch.is_paid) AS escalated_count,
                   SUM(ch.status NOT IN ('bounced','returned') AND NOT ch.is_paid
                       AND ch.cheque_date BETWEEN CURDATE() AND CURDATE() + INTERVAL " . LEGAL_WATCH_DUE_SOON_DAYS . " DAY) AS due_soon_count,
                   SUM(CASE WHEN ch.status NOT IN ('bounced','returned') AND NOT ch.is_paid
                             AND ch.cheque_date BETWEEN CURDATE() AND CURDATE() + INTERVAL " . LEGAL_WATCH_DUE_SOON_DAYS . " DAY
                            THEN ch.open_amt ELSE 0 END) AS due_soon_amt,
                   MIN(CASE WHEN NOT ch.is_paid AND ch.status NOT IN ('bounced','returned')
                             AND ch.cheque_date >= CURDATE() THEN ch.cheque_date END) AS next_due
            FROM ({$chequeSql}) ch
            GROUP BY ch.lease_id
        ) a ON a.lease_id = l.id
        WHERE l.company_id = :c4
          AND " . re_lease_not_deleted_sql($conn, 'l') . "
          AND b.name NOT LIKE '%test building%'
    ";

    $params = [':c1' => $companyId, ':c3' => $companyId, ':c4' => $companyId];
    if ($hasLinks) {
        $params[':c2'] = $companyId;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Open legal cases per lease.
    $cases = [];
    try {
        $cs = $conn->prepare("SELECT lease_id, COUNT(*) FROM re_legal_cases
                              WHERE company_id = ? AND deleted_at IS NULL AND lease_id IS NOT NULL
                                AND status NOT IN ('settled','closed','withdrawn')
                              GROUP BY lease_id");
        $cs->execute([$companyId]);
        $cases = $cs->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) { /* table optional */ }

    // Expired, not renewed: the end date has passed, the lease was not terminated or
    // renewed, no move-out was completed, and the unit has not been leased again
    // (no renewal for the same tenant, no new lease to anyone else) — the tenant is
    // most likely still in the unit without a contract.
    $notRenewed = [];
    try {
        $nr = $conn->prepare("
            SELECT l.id, DATEDIFF(CURDATE(), l.end_date) AS d
            FROM re_leases l
            WHERE l.company_id = ? AND " . re_lease_not_deleted_sql($conn, 'l') . "
              AND l.end_date < CURDATE()
              AND l.status IN ('active','expired','has_legal_case')
              AND COALESCE(l.move_out_completed, 0) = 0
              AND NOT EXISTS (
                  SELECT 1 FROM re_leases r
                  WHERE r.company_id = l.company_id AND r.id <> l.id AND r.deleted_at IS NULL
                    AND (r.parent_lease_id = l.id
                         OR (r.unit_id = l.unit_id AND r.start_date > l.start_date
                             AND (r.tenant_id = l.tenant_id OR r.status <> 'draft'))))");
        $nr->execute([$companyId]);
        $notRenewed = $nr->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) { /* columns optional */ }

    $today = new DateTimeImmutable('today');
    foreach ($rows as &$r) {
        $r['tenant_label'] = legal_tenant_name($r);
        $r['unit_label']   = trim($r['building_name'] . ' ' . $r['unit_number']);
        $r['open_cases']   = (int)($cases[$r['id']] ?? 0);
        $r['days_overdue'] = $r['oldest_overdue']
            ? (int)$today->diff(new DateTimeImmutable($r['oldest_overdue']))->days : 0;
        $r['outstanding']  = (float)$r['bounced_amt'] + (float)$r['overdue_amt'];
        $r['lease_ended']  = in_array($r['status'], ['expired', 'terminated'], true)
            || ($r['end_date'] && $r['end_date'] < $today->format('Y-m-d'));

        $flags = [];
        if ($r['bounced_count'] > 0)  $flags[] = 'bounced';
        if ($r['overdue_count'] > 0)  $flags[] = 'overdue';
        if ($r['held_count'] > 0)     $flags[] = 'held';
        if ($r['escalated_count'] > 0 || $r['open_cases'] > 0 || $r['status'] === 'has_legal_case') $flags[] = 'legal';
        if ($r['lease_ended'] && $r['outstanding'] > 0.005) $flags[] = 'ended_balance';
        $r['not_renewed']  = isset($notRenewed[$r['id']]);
        $r['days_expired'] = (int)($notRenewed[$r['id']] ?? 0);
        if ($r['not_renewed']) $flags[] = 'not_renewed';
        if ($r['due_soon_count'] > 0) $flags[] = 'due_soon';
        $r['flags'] = $flags;

        // Higher = more urgent. Bounced first, then age and size of what is owed.
        $r['score'] = ($r['bounced_count'] > 0 ? 100000 : 0)
            + ($r['lease_ended'] && $r['outstanding'] > 0.005 ? 20000 : 0)
            + ($r['not_renewed'] ? 15000 + min($r['days_expired'], 365) * 10 : 0)
            + min($r['days_overdue'], 365) * 100
            + min($r['outstanding'] / 1000, 999);
    }
    unset($r);

    usort($rows, static fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp((string)$b['start_date'], (string)$a['start_date']));
    return $rows;
}

function legal_lease_watch_totals(array $rows): array
{
    $t = ['bounced_count' => 0, 'bounced_amt' => 0.0, 'bounced_leases' => 0,
          'overdue_count' => 0, 'overdue_amt' => 0.0, 'overdue_leases' => 0,
          'held_count' => 0, 'legal_leases' => 0, 'ended_balance' => 0, 'not_renewed' => 0,
          'due_soon_count' => 0, 'due_soon_amt' => 0.0, 'attention' => 0, 'all' => count($rows)];
    foreach ($rows as $r) {
        $t['bounced_count'] += (int)$r['bounced_count'];
        $t['bounced_amt']   += (float)$r['bounced_amt'];
        $t['overdue_count'] += (int)$r['overdue_count'];
        $t['overdue_amt']   += (float)$r['overdue_amt'];
        $t['held_count']    += (int)$r['held_count'];
        $t['due_soon_count']+= (int)$r['due_soon_count'];
        $t['due_soon_amt']  += (float)$r['due_soon_amt'];
        if ($r['bounced_count'] > 0) $t['bounced_leases']++;
        if ($r['overdue_count'] > 0) $t['overdue_leases']++;
        if (in_array('legal', $r['flags'], true)) $t['legal_leases']++;
        if (in_array('ended_balance', $r['flags'], true)) $t['ended_balance']++;
        if (in_array('not_renewed', $r['flags'], true)) $t['not_renewed']++;
        if (array_intersect(['bounced', 'overdue', 'held', 'legal', 'ended_balance', 'not_renewed'], $r['flags'])) $t['attention']++;
    }
    return $t;
}

/**
 * Cheque-level data for the dashboard charts and alerts, same lease scope as
 * legal_lease_watch_rows(). Returns:
 *   open    — every unpaid bounced/returned or overdue cheque (kind = bounced|overdue)
 *   monthly — last 12 months + this month, by cheque date: cheques paid / bounced / still unpaid (count + AED)
 *   upcoming— unpaid cheques dated today … +30 days, by ISO week
 */
function legal_watch_cheque_insights(PDO $conn, int $companyId): array
{
    $hasLinks = false;
    try {
        $hasLinks = (bool)$conn->query("SHOW TABLES LIKE 're_receipt_cheque_links'")->fetchColumn();
    } catch (Throwable $e) {
    }
    $collSql = "SELECT p.cheque_id, p.amount AS amt FROM re_payments p
                WHERE p.company_id = :c1 AND p.cheque_id IS NOT NULL";
    if ($hasLinks) {
        $collSql .= " UNION ALL SELECT k.cheque_id, k.amount_applied FROM re_receipt_cheque_links k WHERE k.company_id = :c2";
    }
    $sql = "
        SELECT c.id, c.lease_id, c.status, c.cheque_date, c.bounced_date, c.cheque_amount,
               c.cheque_number, c.updated_at,
               LEAST(COALESCE(col.got, 0), c.cheque_amount) AS got,
               (c.status IN ('cleared','paid','deposited') OR COALESCE(col.got,0) >= c.cheque_amount - 0.005) AS is_paid,
               (c.replaced_by_cheque_id IS NOT NULL OR c.settlement_payment_id IS NOT NULL) AS is_settled,
               l.lease_number, b.id AS building_id, b.name AS building_name, u.unit_number,
               t.first_name, t.last_name, t.company_name, t.tenant_type, t.id AS tenant_id
        FROM re_post_dated_cheques c
        JOIN re_leases l ON l.id = c.lease_id
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        LEFT JOIN (SELECT cheque_id, SUM(amt) AS got FROM ({$collSql}) x GROUP BY cheque_id) col ON col.cheque_id = c.id
        WHERE c.company_id = :c3 AND c.status <> 'cancelled'
          AND l.company_id = :c4
          AND " . re_lease_not_deleted_sql($conn, 'l') . "
          AND b.name NOT LIKE '%test building%'
          AND c.cheque_date >= CURDATE() - INTERVAL 3 YEAR
    ";
    $params = [':c1' => $companyId, ':c3' => $companyId, ':c4' => $companyId];
    if ($hasLinks) $params[':c2'] = $companyId;
    $st = $conn->prepare($sql);
    $st->execute($params);

    $today = date('Y-m-d');
    $in30  = date('Y-m-d', strtotime('+30 days'));
    $from  = date('Y-m-01', strtotime('-12 months'));
    $monthly = [];
    for ($m = 12; $m >= 0; $m--) {
        $k = date('Y-m', strtotime("first day of -$m months"));
        $monthly[$k] = ['paid' => 0, 'bounced' => 0, 'unpaid' => 0, 'paid_amt' => 0.0, 'bounced_amt' => 0.0, 'unpaid_amt' => 0.0];
    }
    $open = [];
    $upcoming = [];

    while ($c = $st->fetch(PDO::FETCH_ASSOC)) {
        $amt  = (float)$c['cheque_amount'];
        $owed = max(0.0, $amt - (float)$c['got']);
        $bad  = in_array($c['status'], ['bounced', 'returned'], true);
        $date = (string)$c['cheque_date'];

        if ($date >= $from && $date <= $today) {
            $k = substr($date, 0, 7);
            if (isset($monthly[$k])) {
                $state = $c['is_paid'] ? 'paid' : ($bad ? 'bounced' : 'unpaid');
                $monthly[$k][$state]++;
                $monthly[$k][$state . '_amt'] += $c['is_paid'] ? $amt : $owed;
            }
        }
        if ($c['is_paid']) continue;

        if ($bad && !$c['is_settled']) {
            $kind = 'bounced';
        } elseif (!$bad && $date < $today) {
            $kind = 'overdue';
        } else {
            if (!$bad && $date >= $today && $date <= $in30) {
                $wk = date('Y-m-d', strtotime('monday this week', strtotime($date)));
                $upcoming[$wk] = ($upcoming[$wk] ?? 0) + $owed;
            }
            continue;
        }
        $ref = $kind === 'bounced' && $c['bounced_date'] ? $c['bounced_date'] : $date;
        $c['kind'] = $kind;
        $c['owed'] = $owed;
        $c['age_days'] = max(0, (int)((strtotime($today) - strtotime($ref)) / 86400));
        $c['tenant_label'] = legal_tenant_name($c);
        $open[] = $c;
    }
    ksort($upcoming);
    return ['open' => $open, 'monthly' => $monthly, 'upcoming' => $upcoming];
}

/** Ageing buckets of open (bounced + overdue) amounts. */
function legal_watch_ageing(array $open): array
{
    $buckets = ['0–30' => [0, 30], '31–60' => [31, 60], '61–90' => [61, 90], '91–180' => [91, 180], '180+' => [181, PHP_INT_MAX]];
    $out = [];
    foreach ($buckets as $label => $_) $out[$label] = ['bounced' => 0.0, 'overdue' => 0.0, 'count' => 0];
    foreach ($open as $c) {
        foreach ($buckets as $label => [$lo, $hi]) {
            if ($c['age_days'] >= $lo && $c['age_days'] <= $hi) {
                $out[$label][$c['kind']] += $c['owed'];
                $out[$label]['count']++;
                break;
            }
        }
    }
    return $out;
}

function legal_watch_flag_meta(): array
{
    return [
        'bounced'       => ['Bounced',            'danger',              'bi-x-octagon-fill'],
        'overdue'       => ['Overdue',            'warning text-dark',   'bi-alarm-fill'],
        'held'          => ['Held by finance',    'dark',                'bi-pause-circle-fill'],
        'legal'         => ['With legal',         'primary',             'bi-bank2'],
        'ended_balance' => ['Ended with balance', 'danger-subtle text-danger-emphasis border border-danger-subtle', 'bi-hourglass-bottom'],
        'not_renewed'   => ['Expired, not renewed', 'secondary',       'bi-calendar-x-fill'],
        'due_soon'      => ['Due ≤14 days',       'info-subtle text-info-emphasis border border-info-subtle', 'bi-calendar-event'],
    ];
}

// ---- Small display helpers shared by every Legal screen ----

function lw_aed(float $v): string
{
    if ($v >= 1000000) return number_format($v / 1000000, 2) . 'M';
    if ($v >= 10000)   return number_format($v / 1000, 1) . 'k';
    return number_format($v, 0);
}

function lw_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $ini = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[1] ?? '', 0, 1));
    return $ini !== '' ? $ini : '?';
}

function lw_avatar_tone(string $name): int
{
    return abs(crc32($name)) % 6;
}

function lw_spark(array $points): string
{
    $vals = array_column($points, 1);
    if (count($vals) < 2) return '';
    $w = 84; $hgt = 30; $pad = 3;
    $max = max($vals) ?: 1; $min = min($vals);
    $span = ($max - $min) ?: 1;
    $xy = [];
    foreach ($vals as $i => $v) {
        $xy[] = [round($pad + $i * ($w - 2 * $pad) / (count($vals) - 1), 1),
                 round($hgt - $pad - ($v - $min) / $span * ($hgt - 2 * $pad), 1)];
    }
    $d = 'M' . $xy[0][0] . ' ' . $xy[0][1];
    for ($i = 1; $i < count($xy); $i++) {
        $cx = round(($xy[$i - 1][0] + $xy[$i][0]) / 2, 1);
        $d .= " C{$cx} {$xy[$i - 1][1]} {$cx} {$xy[$i][1]} {$xy[$i][0]} {$xy[$i][1]}";
    }
    [$lx, $ly] = end($xy);
    $tip = implode(', ', array_map(fn($p) => $p[0] . ' ' . $p[1], $points));
    return '<svg class="lw-spark" viewBox="0 0 ' . $w . ' ' . $hgt . '" role="img" aria-label="' . htmlspecialchars($tip) . '">'
        . '<title>' . htmlspecialchars($tip) . '</title>'
        . '<path d="' . $d . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>'
        . '<circle cx="' . $lx . '" cy="' . $ly . '" r="3" fill="currentColor"/></svg>';
}
