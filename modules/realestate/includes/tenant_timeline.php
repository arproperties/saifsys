<?php
/**
 * Tenant Timeline: one date-ordered history of a tenant, shown on the tenant
 * page (Real Estate) and on the Legal module's Tenant Timeline page.
 *
 * Lease-side items are read from what the lease screens already store
 * (leases, move-in/out, renewals, bounced cheques, tenant issues).
 * Legal items (cases, stages, hearings, offers, notices, legal issues,
 * PDF-vs-saifsys checks) are entered in the Legal module and are only
 * included when $withLegal is true (see legal_can_view_timeline()).
 *
 * Read-only: this file never writes.
 */

if (!function_exists('tt_h')) {
    function tt_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/** "101,102,103,105" -> "101–103, 105" */
function tt_rooms_label(array $rooms): string {
    $rooms = array_values(array_unique(array_filter(array_map('strval', $rooms), 'strlen')));
    if (!$rooms) return '';
    $allNum = count(array_filter($rooms, 'ctype_digit')) === count($rooms);
    if (!$allNum) {
        natsort($rooms);
        return implode(', ', $rooms);
    }
    $n = array_map('intval', $rooms);
    sort($n);
    $out = [];
    $start = $prev = $n[0];
    for ($i = 1, $c = count($n); $i <= $c; $i++) {
        $cur = $n[$i] ?? null;
        if ($cur !== null && $cur === $prev + 1) { $prev = $cur; continue; }
        $out[] = $start === $prev ? (string)$start : ($start . '–' . $prev);
        if ($cur !== null) { $start = $prev = $cur; }
    }
    return implode(', ', $out);
}

function tt_money($v): string {
    return $v === null || $v === '' ? '' : 'AED ' . number_format((float)$v, 2);
}

function tt_date_label(?string $d): string {
    if (!$d || $d === '0000-00-00') return '';
    $t = strtotime($d);
    return $t ? date('d M Y', $t) : '';
}

/** Safe query helper: a missing table/column on an older database returns []. */
function tt_rows(PDO $conn, string $sql, array $vals): array {
    try {
        $st = $conn->prepare($sql);
        $st->execute($vals);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('tenant_timeline: ' . $e->getMessage());
        return [];
    }
}

/**
 * @return array{leases: array, items: array, alerts: array, checks: array, with_legal: bool}
 */
function tenant_timeline_build(PDO $conn, int $companyId, int $tenantId, bool $withLegal, string $rePath = '', string $legalPath = '../legal/'): array {
    $today = date('Y-m-d');
    $items = [];
    $add = function (array $it) use (&$items, $today) {
        if (empty($it['date']) || $it['date'] === '0000-00-00') return;
        $it['date'] = substr($it['date'], 0, 10);
        $it['upcoming'] = $it['date'] > $today;
        $it += ['date_label' => null, 'lease_id' => 0, 'detail' => '', 'url' => null, 'badge' => null, 'review' => false, 'tone' => 'default', 'seq' => 0];
        $items[] = $it;
    };

    // ---- Leases (with every room on multi-unit leases) ----
    $leases = tt_rows($conn, "
        SELECT l.*, u.unit_number, b.name AS building_name
        FROM re_leases l
        LEFT JOIN re_units u ON u.id = l.unit_id
        LEFT JOIN re_buildings b ON b.id = u.building_id
        WHERE l.tenant_id = ? AND l.company_id = ?
        ORDER BY l.start_date, l.id
    ", [$tenantId, $companyId]);
    $leaseIds = array_map(fn($l) => (int)$l['id'], $leases);
    $byId = [];
    foreach ($leases as &$l) {
        $rooms = array_column(tt_rows($conn, "
            SELECT u.unit_number FROM re_lease_units lu JOIN re_units u ON u.id = lu.unit_id
            WHERE lu.lease_id = ? ORDER BY lu.sort_order, lu.id
        ", [(int)$l['id']]), 'unit_number');
        if (!$rooms && $l['unit_number'] !== null) $rooms = [$l['unit_number']];
        $l['rooms'] = $rooms;
        $l['rooms_label'] = tt_rooms_label($rooms);
        $l['label'] = ($l['lease_number'] ?: 'L-' . $l['id']);
        $byId[(int)$l['id']] = $l;
    }
    unset($l);

    $where = function (string $col) use ($leaseIds): array {
        if (!$leaseIds) return ['1 = 0', []];
        return [$col . ' IN (' . implode(',', array_fill(0, count($leaseIds), '?')) . ')', $leaseIds];
    };
    $roomsOf = function (int $leaseId) use ($byId): string {
        if (!isset($byId[$leaseId])) return '';
        $r = $byId[$leaseId]['rooms'];
        return (count($r) > 1 ? count($r) . ' rooms: ' : 'Unit ') . $byId[$leaseId]['rooms_label'];
    };

    foreach ($leases as $l) {
        $lid = (int)$l['id'];
        $url = $rePath . 'lease_view.php?id=' . $lid;
        $rent = (float)($l['annual_rent'] ?? 0) > 0 ? ' · Rent ' . tt_money($l['annual_rent']) . '/yr' : '';
        $where1 = trim(($l['building_name'] ?? '') . ' · ' . $roomsOf($lid), ' ·');
        if ($l['status'] === 'draft') {
            $add(['date' => $l['created_at'], 'cat' => 'renewal', 'lease_id' => $lid, 'url' => $url,
                'title' => (!empty($l['is_renewal_lease']) ? 'Draft renewal lease created' : 'Draft lease created') . ' — ' . $l['label'],
                'detail' => 'Proposed ' . tt_date_label($l['start_date']) . ' – ' . tt_date_label($l['end_date']) . $rent, 'badge' => 'Draft']);
            continue;
        }
        $isRenewal = !empty($l['is_renewal_lease']) || !empty($l['parent_lease_id']);
        $parent = !empty($l['parent_lease_id']) && isset($byId[(int)$l['parent_lease_id']]) ? ' (renews ' . $byId[(int)$l['parent_lease_id']]['label'] . ')' : '';
        // One line per lease: start – end (terminations stay a separate red line)
        $period = tt_date_label($l['start_date']) . ' – ' . tt_date_label($l['status'] === 'terminated' && !empty($l['termination_date']) ? $l['termination_date'] : $l['end_date']);
        $add(['date' => $l['start_date'], 'cat' => 'lease', 'lease_id' => $lid, 'url' => $url, 'seq' => 1, 'tone' => 'good',
            'date_label' => $period,
            'title' => ($isRenewal ? 'Renewal lease ' : 'Lease ') . $l['label'] . ' · ' . $roomsOf($lid),
            'detail' => trim(($l['building_name'] ?? '') . $parent . $rent . ' · Status in saifsys: ' . ucfirst((string)$l['status']), ' ·'),
            'badge' => ucfirst((string)$l['status'])]);
        if ($l['status'] === 'terminated' && !empty($l['termination_date'])) {
            $add(['date' => $l['termination_date'], 'cat' => 'lease', 'lease_id' => $lid, 'url' => $url, 'tone' => 'bad',
                'title' => 'Lease terminated — ' . $l['label'], 'detail' => (string)($l['termination_reason'] ?? '')]);
        }
        if (!empty($l['move_out_date'])) {
            $add(['date' => $l['move_out_date'], 'cat' => 'move', 'lease_id' => $lid, 'url' => $url,
                'title' => 'Moved out — ' . $l['label'], 'detail' => $where1]);
        }
    }

    // ---- Move-ins / move-out notices / move-outs ----
    [$w, $v] = $where('lease_id');
    foreach (tt_rows($conn, "SELECT id, lease_id, move_in_date, status FROM re_move_ins WHERE $w AND status <> 'cancelled'", $v) as $r) {
        $add(['date' => $r['move_in_date'], 'cat' => 'move', 'lease_id' => (int)$r['lease_id'], 'url' => $rePath . 'lease_view.php?id=' . (int)$r['lease_id'],
            'title' => 'Move-in' . ($r['status'] === 'completed' ? '' : ' (' . str_replace('_', ' ', $r['status']) . ')'), 'detail' => $roomsOf((int)$r['lease_id'])]);
    }
    foreach (tt_rows($conn, "SELECT lease_id, notice_date, intended_move_out_date, notice_type, notice_reason, status FROM re_move_out_notices WHERE $w AND status <> 'cancelled'", $v) as $r) {
        $add(['date' => $r['notice_date'], 'cat' => 'move', 'lease_id' => (int)$r['lease_id'], 'url' => $rePath . 'lease_view.php?id=' . (int)$r['lease_id'],
            'title' => 'Move-out notice (' . $r['notice_type'] . ')', 'detail' => 'Intended move-out ' . tt_date_label($r['intended_move_out_date']) . ($r['notice_reason'] ? ' · ' . $r['notice_reason'] : '')]);
    }

    // ---- Renewals ----
    foreach (tt_rows($conn, "SELECT id, lease_id, initiated_date, notice_sent_at, proposed_rent, proposed_start_date, proposed_end_date, tenant_response, tenant_response_date, status FROM re_lease_renewal_workflows WHERE $w", $v) as $r) {
        $lid = (int)$r['lease_id'];
        $url = $rePath . 'lease_renewal_workflow_view.php?id=' . (int)$r['id'];
        $prop = 'Proposed ' . tt_money($r['proposed_rent']) . '/yr, ' . tt_date_label($r['proposed_start_date']) . ' – ' . tt_date_label($r['proposed_end_date']);
        if (!empty($r['notice_sent_at'])) {
            $add(['date' => $r['notice_sent_at'], 'cat' => 'renewal', 'lease_id' => $lid, 'url' => $url, 'title' => 'Renewal offer sent', 'detail' => $prop, 'badge' => str_replace('_', ' ', (string)$r['status'])]);
        } else {
            $add(['date' => $r['initiated_date'], 'cat' => 'renewal', 'lease_id' => $lid, 'url' => $url, 'title' => 'Renewal started', 'detail' => $prop, 'badge' => str_replace('_', ' ', (string)$r['status'])]);
        }
        if (!empty($r['tenant_response_date'])) {
            $add(['date' => $r['tenant_response_date'], 'cat' => 'renewal', 'lease_id' => $lid, 'url' => $url, 'title' => 'Tenant replied to renewal', 'detail' => (string)$r['tenant_response']]);
        }
    }

    // ---- Bounced cheques ----
    foreach (tt_rows($conn, "SELECT id, lease_id, cheque_number, cheque_amount, bounced_date, bounced_reason, status FROM re_post_dated_cheques WHERE $w AND bounced_date IS NOT NULL", $v) as $r) {
        $add(['date' => $r['bounced_date'], 'cat' => 'cheque', 'lease_id' => (int)$r['lease_id'], 'url' => $rePath . 'billing_cheque_view.php?id=' . (int)$r['id'], 'tone' => 'bad',
            'title' => 'Cheque bounced — ' . $r['cheque_number'] . ' (' . tt_money($r['cheque_amount']) . ')', 'detail' => trim(($r['bounced_reason'] ?? '') . ' · now ' . str_replace('_', ' ', $r['status']), ' ·')]);
    }

    // ---- Tenant issues & violations (Real Estate) ----
    foreach (tt_rows($conn, "SELECT lease_id, issue_type, severity, title, reported_date, status FROM re_tenant_issues_violations WHERE tenant_id = ?", [$tenantId]) as $r) {
        $add(['date' => $r['reported_date'], 'cat' => 'issue', 'lease_id' => (int)$r['lease_id'], 'url' => $rePath . 'tenant_view.php?id=' . $tenantId,
            'title' => ucfirst($r['issue_type']) . ': ' . $r['title'], 'detail' => 'Severity ' . $r['severity'] . ' · ' . str_replace('_', ' ', $r['status'])]);
    }

    $alerts = [];
    $checks = [];
    $leaseCases = [];

    if ($withLegal) {
        $schema = function_exists('legal_timeline_schema_ready') && legal_timeline_schema_ready($conn);
        // Cases of this tenant: own tenant_id, one of its leases, or linked to them
        [$wl, $vl] = $where('c.lease_id');
        [$wk, $vk] = $where('k.link_id');
        $cases = tt_rows($conn, "
            SELECT DISTINCT c.* FROM re_legal_cases c
            LEFT JOIN re_legal_case_links k ON k.case_id = c.id
            WHERE c.company_id = ? AND c.deleted_at IS NULL
              AND (c.tenant_id = ? OR $wl OR (k.link_type = 'tenant' AND k.link_id = ?) OR (k.link_type = 'lease' AND $wk))
            ORDER BY c.id
        ", array_merge([$companyId, $tenantId], $vl, [$tenantId], $vk));
        $caseIds = [];
        $statuses = function_exists('legal_case_statuses') ? legal_case_statuses() : [];
        foreach ($cases as $c) {
            $cid = (int)$c['id'];
            $caseIds[] = $cid;
            $url = $legalPath . 'legal_case_view.php?id=' . $cid;
            $ref = $c['external_reference'] ? ' (' . $c['external_reference'] . ')' : '';
            $lid = (int)($c['lease_id'] ?? 0);
            $review = !empty($c['needs_review']);
            $leaseCases[$lid][] = $c;
            if (!empty($c['opened_date'])) $add(['date' => $c['opened_date'], 'cat' => 'legal', 'lease_id' => $lid, 'url' => $url, 'review' => $review, 'tone' => 'legal',
                'title' => 'Legal case opened — ' . $c['case_number'] . $ref, 'detail' => $c['title'] . ($lid ? ' · ' . $roomsOf($lid) : ''),
                'badge' => $statuses[$c['status']] ?? $c['status']]);
            if (!empty($c['filed_date'])) {
                $add(['date' => $c['filed_date'], 'cat' => 'legal', 'lease_id' => $lid, 'url' => $url, 'review' => $review, 'tone' => 'legal',
                    'title' => 'Case filed — ' . ($c['external_reference'] ?: $c['case_number'])]);
            }
            if (!empty($c['closed_date'])) {
                $add(['date' => $c['closed_date'], 'cat' => 'legal', 'lease_id' => $lid, 'url' => $url, 'tone' => 'legal',
                    'title' => 'Case ' . strtolower($statuses[$c['status']] ?? 'closed') . ' — ' . $c['case_number']]);
            }
            if (!in_array($c['status'], ['settled', 'closed', 'withdrawn'], true)) {
                $alerts[] = ['type' => 'case', 'text' => 'Open legal case ' . $c['case_number'] . $ref . ' — ' . ($statuses[$c['status']] ?? $c['status']) . ($lid && isset($byId[$lid]) ? ' · ' . $byId[$lid]['label'] : ''), 'url' => $url,
                    'next' => $c['next_action_date'] ?? null];
            }
            if ($schema) {
                foreach (legal_fetch_case_stages($conn, $cid) as $s) {
                    $types = legal_stage_types();
                    $add(['date' => $s['stage_date'], 'cat' => 'legal', 'lease_id' => $lid, 'url' => $url, 'review' => !empty($s['needs_review']), 'tone' => 'legal',
                        'title' => ($types[$s['stage_type']] ?? $s['stage_type']) . ($s['reference_number'] ? ' — ' . $s['reference_number'] : '') . ' · case ' . ($c['external_reference'] ?: $c['case_number']),
                        'detail' => trim(($s['outcome'] ?? '') . ($s['awarded_amount'] !== null ? ' · Awarded ' . tt_money($s['awarded_amount']) : ''))]);
                }
                foreach (legal_fetch_case_offers($conn, $cid) as $o) {
                    $parties = legal_offer_parties();
                    $add(['date' => $o['offer_date'] ?: $o['response_date'], 'cat' => 'legal', 'lease_id' => $lid, 'url' => $url, 'review' => !empty($o['needs_review']), 'tone' => 'legal',
                        'title' => 'Settlement offer by ' . strtolower($parties[$o['offered_by']] ?? $o['offered_by']) . ($o['amount'] !== null ? ' — ' . tt_money($o['amount']) : ''),
                        'detail' => trim((string)($o['notes'] ?? '')), 'badge' => legal_offer_statuses()[$o['status']] ?? $o['status']]);
                }
            }
            foreach (tt_rows($conn, "SELECT * FROM re_legal_hearings WHERE case_id = ? AND company_id = ?", [$cid, $companyId]) as $hr) {
                $isEviction = ($hr['event_type'] ?? '') === 'eviction';
                $add(['date' => $hr['scheduled_date'], 'cat' => 'legal', 'lease_id' => $lid, 'url' => $url, 'review' => !empty($hr['needs_review']), 'tone' => $isEviction ? 'bad' : 'legal',
                    'title' => ($isEviction ? 'EVICTION — ' : '') . $hr['title'], 'detail' => trim(($hr['description'] ?? '') . ($hr['outcome'] ? ' · Outcome: ' . $hr['outcome'] : '')),
                    'badge' => ucfirst((string)$hr['status'])]);
                if ($hr['status'] === 'scheduled' && $hr['scheduled_date'] >= $today && $hr['scheduled_date'] <= date('Y-m-d', strtotime('+30 days'))) {
                    $alerts[] = ['type' => $isEviction ? 'eviction' : 'hearing', 'text' => ($isEviction ? 'Eviction' : 'Hearing') . ' on ' . tt_date_label($hr['scheduled_date']) . ' — ' . $hr['title'], 'url' => $url, 'next' => $hr['scheduled_date'], 'lease_id' => $lid];
                }
            }
        }

        // Notices: tenant, its leases, or its cases
        [$wn, $vn] = $where('n.lease_id');
        $caseIn = $caseIds ? 'n.case_id IN (' . implode(',', array_map('intval', $caseIds)) . ')' : '1 = 0';
        $notices = tt_rows($conn, "SELECT n.* FROM re_legal_notices n WHERE n.company_id = ? AND (n.tenant_id = ? OR $wn OR $caseIn)", array_merge([$companyId, $tenantId], $vn));
        $ntypes = function_exists('legal_notice_types') ? legal_notice_types() : [];
        $nmeth = function_exists('legal_notice_delivery_methods') ? legal_notice_delivery_methods() : [];
        foreach ($notices as $n) {
            $add(['date' => $n['issue_date'] ?: $n['created_at'], 'cat' => 'legal', 'lease_id' => (int)$n['lease_id'], 'url' => $legalPath . 'legal_notice_view.php?id=' . (int)$n['id'],
                'review' => !empty($n['needs_review']), 'tone' => 'legal',
                'title' => 'Legal notice — ' . ($ntypes[$n['notice_type']] ?? $n['notice_type']) . ' · ' . $n['reference_number'],
                'detail' => trim('By ' . ($nmeth[$n['delivery_method']] ?? $n['delivery_method']) . ($n['recipient_email'] ? ' to ' . $n['recipient_email'] : '') . ' · ' . $n['subject']),
                'badge' => ucfirst(str_replace('_', ' ', (string)$n['delivery_status']))]);
        }

        // Legal issues register
        [$wi, $vi] = $where('i.lease_id');
        foreach (tt_rows($conn, "SELECT i.* FROM re_legal_issues i WHERE i.company_id = ? AND i.deleted_at IS NULL AND (i.tenant_id = ? OR $wi)", array_merge([$companyId, $tenantId], $vi)) as $i) {
            $add(['date' => $i['issue_date'] ?: $i['created_at'], 'cat' => 'legal', 'lease_id' => (int)$i['lease_id'], 'url' => $legalPath . 'legal_issue_view.php?id=' . (int)$i['id'], 'tone' => 'legal',
                'title' => 'Legal issue ' . $i['issue_number'] . ' — ' . $i['title'], 'badge' => ucfirst(str_replace('_', ' ', (string)$i['status']))]);
        }

        if ($schema) {
            $checks = legal_fetch_data_checks($conn, $companyId, null, $tenantId);
            $open = count(array_filter($checks, fn($c) => $c['status'] === 'open'));
            if ($open) {
                $alerts[] = ['type' => 'check', 'text' => $open . ' difference(s) between outside documents and saifsys still open', 'url' => '#tt-checks', 'next' => null];
            }
        }
        $toReview = count(array_filter($items, fn($i) => $i['review']));
        if ($toReview) {
            $alerts[] = ['type' => 'review', 'text' => $toReview . ' legal entr' . ($toReview === 1 ? 'y was' : 'ies were') . ' imported and not yet checked (marked “Check”)', 'url' => null, 'next' => null];
        }
    }

    usort($items, function ($a, $b) {
        return [$b['date'], $b['seq']] <=> [$a['date'], $a['seq']];
    });

    return ['leases' => $leases, 'items' => $items, 'alerts' => $alerts, 'checks' => $checks, 'with_legal' => $withLegal, 'lease_cases' => $leaseCases];
}

function tt_short_date(?string $d): string {
    if (!$d) return '';
    $t = strtotime($d);
    return $t ? date('j M', $t) . (date('Y', $t) !== date('Y') ? ' ' . date('Y', $t) : '') : '';
}

function tt_rooms_short(array $l): string {
    $n = count($l['rooms']);
    return $n > 4 ? $n . ' rooms' : ($l['rooms_label'] !== '' ? $l['rooms_label'] : '—');
}

/**
 * Render the timeline card (simple layout): one-line warning, one row per lease,
 * differences folded away, one line per history event.
 * $opts: rePath, legalPath, checksEditable (bool), csrf (hidden inputs html), title.
 */
function tenant_timeline_render(array $tl, array $opts = []): void {
    $legalPath = $opts['legalPath'] ?? '../legal/';
    $editable = !empty($opts['checksEditable']);
    $csrf = $opts['csrf'] ?? '';
    $withLegal = !empty($tl['with_legal']);
    $today = date('Y-m-d');
    $uid = 'tt' . substr(md5((string)mt_rand()), 0, 6);
    $statuses = function_exists('legal_case_statuses') ? legal_case_statuses() : [];
    $leaseCases = $tl['lease_cases'] ?? [];
    $byLease = [];
    foreach ($tl['leases'] as $l) $byLease[(int)$l['id']] = $l;
    $totalRooms = array_sum(array_map(fn($l) => $l['status'] === 'draft' ? 0 : count($l['rooms']), $tl['leases']));

    // ---- One-line warning ----
    $parts = [];
    foreach ($tl['alerts'] as $a) {
        if ($a['type'] === 'eviction') {
            $lid = (int)($a['lease_id'] ?? 0);
            $parts[] = ['bad', 'Eviction ' . tt_short_date($a['next']) . (isset($byLease[$lid]) ? ' (' . tt_rooms_short($byLease[$lid]) . ')' : '')];
        }
    }
    $nHear = count(array_filter($tl['alerts'], fn($a) => $a['type'] === 'hearing'));
    if ($nHear) $parts[] = ['warn', $nHear . ' hearing' . ($nHear > 1 ? 's' : '') . ' in the next 30 days'];
    $nCase = count(array_filter($tl['alerts'], fn($a) => $a['type'] === 'case'));
    if ($nCase) $parts[] = ['warn', $nCase . ' open case' . ($nCase > 1 ? 's' : '')];
    $nOpenChecks = count(array_filter($tl['checks'], fn($c) => $c['status'] === 'open'));
    $nReview = count(array_filter($tl['items'], fn($i) => $i['review']));
    $nCheck = $nOpenChecks + $nReview;
    if ($nCheck) $parts[] = ['warn', $nCheck . ' thing' . ($nCheck > 1 ? 's' : '') . ' to check'];

    // ---- Next upcoming thing per lease ----
    $next = [];
    foreach (array_reverse($tl['items']) as $it) { // oldest first
        $lid = (int)$it['lease_id'];
        if ($it['date'] >= $today && $lid && !isset($next[$lid])) $next[$lid] = $it;
    }
    ?>
    <style>
        .tt-card .tt-warn{font-weight:600}
        .tt-card .tt-warn .bad{color:#b02a37}
        .tt-card .tt-leases tr[data-lease]{cursor:pointer}
        .tt-card .tt-leases tr.tt-sel>td{background:#e7f1ff}
        .tt-card .tt-line{display:flex;gap:.6rem;align-items:baseline;padding:.35rem .25rem;border-bottom:1px solid rgba(0,0,0,.06);cursor:pointer}
        .tt-card .tt-line:hover{background:rgba(0,0,0,.025)}
        .tt-card .tt-date{flex:0 0 190px;font-size:.82rem;color:#6c757d;white-space:nowrap}
        .tt-card .tt-dot{flex:0 0 9px;width:9px;height:9px;border-radius:50%;background:#adb5bd;align-self:center}
        .tt-card .tt-line[data-tone=good] .tt-dot{background:#198754}
        .tt-card .tt-line[data-tone=bad] .tt-dot{background:#dc3545}
        .tt-card .tt-line[data-tone=legal] .tt-dot{background:#6f42c1}
        .tt-card .tt-line.tt-upcoming .tt-date{color:#0d6efd;font-weight:600}
        .tt-card .tt-line[data-tone=bad] .tt-text{color:#b02a37;font-weight:600}
        .tt-card .tt-more{display:none;font-size:.85rem;color:#495057;padding:.2rem .25rem .5rem 210px;white-space:pre-line;border-bottom:1px solid rgba(0,0,0,.06)}
        .tt-card .tt-more.open{display:block}
        .tt-card .tt-check-src{background:#fff8e1}
        .tt-card .tt-check-sys{background:#e8f4fd}
        .tt-card details>summary{cursor:pointer}
        @media (max-width:576px){.tt-card .tt-date{flex-basis:92px;white-space:normal}.tt-card .tt-more{padding-left:.25rem}}
    </style>
    <div class="card mb-4 tt-card" id="<?= $uid ?>">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="mb-0"><i class="bi bi-clock-history"></i> <?= tt_h($opts['title'] ?? 'Tenant Timeline') ?></h5>
            <span class="text-muted small"><?= count($tl['leases']) ?> lease<?= count($tl['leases']) === 1 ? '' : 's' ?> · <?= (int)$totalRooms ?> room<?= $totalRooms === 1 ? '' : 's' ?></span>
        </div>
        <div class="card-body">
            <?php if ($parts): ?>
                <details class="alert alert-warning py-2 mb-3">
                    <summary class="tt-warn"><i class="bi bi-exclamation-triangle"></i>
                        <?php foreach ($parts as $i => [$cls, $txt]): ?><?= $i ? ' · ' : ' ' ?><span class="<?= $cls ?>"><?= tt_h($txt) ?></span><?php endforeach; ?>
                    </summary>
                    <ul class="mb-0 mt-2 ps-3 small">
                        <?php foreach ($tl['alerts'] as $a): ?>
                            <li><?php if ($a['url']): ?><a href="<?= tt_h($a['url']) ?>"><?= tt_h($a['text']) ?></a><?php else: ?><?= tt_h($a['text']) ?><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <?php if ($tl['leases']): ?>
            <div class="table-responsive mb-1">
                <table class="table table-sm table-hover align-middle mb-0 tt-leases">
                    <thead class="table-light"><tr><th>Lease</th><th>Rooms</th><th>Period</th><?php if ($withLegal): ?><th>Case</th><?php endif; ?><th>Next</th></tr></thead>
                    <tbody>
                    <?php foreach ($tl['leases'] as $l):
                        $lid = (int)$l['id'];
                        $end = $l['status'] === 'terminated' && !empty($l['termination_date']) ? $l['termination_date'] : $l['end_date'];
                        $n = $next[$lid] ?? null; ?>
                        <tr data-lease="<?= $lid ?>">
                            <td><a href="<?= tt_h(($opts['rePath'] ?? '') . 'lease_view.php?id=' . $lid) ?>" class="fw-semibold text-decoration-none"><?= tt_h($l['label']) ?></a>
                                <span class="badge bg-light text-dark border ms-1"><?= tt_h(ucfirst((string)$l['status'])) ?></span></td>
                            <td title="<?= tt_h($l['rooms_label']) ?>"><?= tt_h(tt_rooms_short($l)) ?></td>
                            <td class="small text-nowrap"><?= tt_h(tt_short_date($l['start_date']) . ' – ' . tt_short_date($end)) ?></td>
                            <?php if ($withLegal): ?>
                            <td class="small"><?php if (empty($leaseCases[$lid])): ?><span class="text-muted">–</span><?php else: foreach ($leaseCases[$lid] as $i => $c): ?>
                                <?= $i ? '<br>' : '' ?><a href="<?= tt_h($legalPath . 'legal_case_view.php?id=' . (int)$c['id']) ?>" class="text-decoration-none"><?= tt_h($c['external_reference'] ?: $c['case_number']) ?></a>
                                <span class="text-muted"><?= tt_h($statuses[$c['status']] ?? $c['status']) ?></span>
                            <?php endforeach; endif; ?></td>
                            <?php endif; ?>
                            <td class="small"><?php if ($n): ?><span class="<?= $n['tone'] === 'bad' ? 'text-danger fw-semibold' : '' ?>"><?= tt_h(tt_short_date($n['date']) . ' · ' . (strpos($n['title'], 'EVICTION') === 0 ? 'Eviction' : preg_replace('/ · case .*$/', '', $n['title']))) ?></span>
                                <?php elseif (!in_array($l['status'], ['terminated', 'expired', 'draft'], true) && $end && $end >= $today): ?>Lease ends <?= tt_h(tt_short_date($end)) ?>
                                <?php else: ?><span class="text-muted">–</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="small text-muted mb-3">Click a lease row to see only its history. <a href="#" class="tt-all" style="display:none">Show all leases</a></div>
            <?php endif; ?>

            <?php if ($withLegal && ($tl['checks'] || $editable)): ?>
                <details id="tt-checks" class="mb-3 border rounded p-2">
                    <summary class="fw-semibold"><i class="bi bi-file-earmark-diff"></i> <?= $nOpenChecks ?> difference<?= $nOpenChecks === 1 ? '' : 's' ?> to check (outside document vs saifsys)<?php $nRes = count($tl['checks']) - $nOpenChecks; if ($nRes): ?> <span class="text-muted fw-normal small">· <?= $nRes ?> resolved</span><?php endif; ?></summary>
                    <?php if (!$tl['checks']): ?>
                        <p class="text-muted small my-2">No differences recorded.</p>
                    <?php else: ?>
                    <div class="table-responsive mt-2"><table class="table table-sm align-middle mb-1">
                        <thead><tr><th>Topic</th><th>Document says</th><th>saifsys says</th><th>Status</th><?php if ($editable): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($tl['checks'] as $c): ?>
                            <tr class="tt-check-row" data-lease="<?= (int)$c['lease_id'] ?>">
                                <td><strong><?= tt_h($c['topic']) ?></strong>
                                    <?php if ($c['lease_number']): ?><br><small class="text-muted"><?= tt_h($c['lease_number']) ?></small><?php endif; ?>
                                    <?php if ($c['case_id']): ?><br><small><a href="<?= tt_h($legalPath . 'legal_case_view.php?id=' . (int)$c['case_id']) ?>"><?= tt_h($c['external_reference'] ?: $c['case_number']) ?></a></small><?php endif; ?>
                                </td>
                                <td class="tt-check-src small"><?php if ($c['source_label']): ?><span class="text-muted d-block"><?= tt_h($c['source_label']) ?></span><?php endif; ?><?= nl2br(tt_h($c['source_value'])) ?></td>
                                <td class="tt-check-sys small"><?= nl2br(tt_h($c['system_value'])) ?></td>
                                <td>
                                    <?php if ($c['status'] === 'open'): ?><span class="badge bg-warning text-dark">Open</span>
                                    <?php else: ?><span class="badge bg-success">Resolved</span>
                                        <?php if ($c['resolution']): ?><div class="small mt-1"><?= nl2br(tt_h($c['resolution'])) ?></div><?php endif; ?>
                                        <div class="small text-muted"><?= tt_h(($c['resolved_name'] ?? '') . ' ' . tt_date_label($c['resolved_at'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <?php if ($editable): ?>
                                <td style="min-width:220px">
                                    <?php if ($c['status'] === 'open'): ?>
                                        <form method="POST" class="d-flex gap-1"><?= $csrf ?>
                                            <input type="hidden" name="action" value="resolve_check"><input type="hidden" name="check_id" value="<?= (int)$c['id'] ?>">
                                            <input type="text" name="resolution" class="form-control form-control-sm" placeholder="What is correct?" required>
                                            <button class="btn btn-sm btn-success" title="Mark resolved"><i class="bi bi-check2"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST"><?= $csrf ?><input type="hidden" name="action" value="reopen_check"><input type="hidden" name="check_id" value="<?= (int)$c['id'] ?>">
                                            <button class="btn btn-sm btn-outline-secondary">Reopen</button></form>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php endif; ?>
                    <?php if (!$editable && $tl['checks']): ?><small class="text-muted">Resolve these in the Legal module → Tenant Timeline.</small><?php endif; ?>
                </details>
            <?php endif; ?>

            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <h6 class="mb-0 me-2">History</h6>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary active tt-f" data-f="">All</button>
                    <button type="button" class="btn btn-outline-secondary tt-f" data-f="lease">Leases</button>
                    <?php if ($withLegal): ?><button type="button" class="btn btn-outline-secondary tt-f" data-f="legal">Legal</button><?php endif; ?>
                </div>
                <small class="text-muted">Click a line for details.</small>
            </div>

            <?php if (!$tl['items']): ?>
                <p class="text-muted mb-0">Nothing recorded for this tenant yet.</p>
            <?php else: ?>
                <div class="tt-list">
                <?php foreach ($tl['items'] as $it):
                    $hasMore = $it['detail'] !== '' || $it['url']; ?>
                    <div class="tt-line<?= $it['upcoming'] ? ' tt-upcoming' : '' ?>" data-cat="<?= $it['cat'] === 'legal' ? 'legal' : 'lease' ?>" data-lease="<?= (int)$it['lease_id'] ?>" data-tone="<?= tt_h($it['tone']) ?>">
                        <div class="tt-date"><?= tt_h($it['date_label'] ?? tt_date_label($it['date'])) ?></div>
                        <div class="tt-dot"></div>
                        <div class="flex-grow-1 tt-text"><?= tt_h($it['title']) ?>
                            <?php if ($it['review']): ?><span class="badge bg-warning text-dark ms-1" title="Imported, not yet checked by a person">Check</span><?php endif; ?>
                            <?php if ($it['upcoming']): ?><span class="badge bg-primary ms-1">Upcoming</span><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($hasMore): ?>
                    <div class="tt-more" data-cat="<?= $it['cat'] === 'legal' ? 'legal' : 'lease' ?>" data-lease="<?= (int)$it['lease_id'] ?>">
                        <?php if ($it['badge']): ?><span class="badge bg-light text-dark border me-1"><?= tt_h($it['badge']) ?></span><?php endif; ?><?= tt_h($it['detail']) ?>
                        <?php if ($it['url']): ?> <a href="<?= tt_h($it['url']) ?>" class="ms-1">Open <i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                </div>
                <p class="text-muted small mb-0 mt-2 tt-empty" style="display:none">Nothing for this filter.</p>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function () {
        var root = document.getElementById(<?= json_encode($uid) ?>);
        if (!root) return;
        var lease = '', cat = '';
        var allLink = root.querySelector('.tt-all');
        function apply() {
            var shown = 0;
            root.querySelectorAll('.tt-line').forEach(function (el) {
                var l = el.getAttribute('data-lease');
                var ok = (!cat || el.getAttribute('data-cat') === cat) && (!lease || l === lease || l === '0');
                el.style.display = ok ? '' : 'none';
                var more = el.nextElementSibling;
                if (more && more.classList.contains('tt-more') && !ok) more.classList.remove('open');
                if (ok) shown++;
            });
            root.querySelectorAll('.tt-check-row').forEach(function (el) {
                var l = el.getAttribute('data-lease');
                el.style.display = (!lease || l === lease || l === '0') ? '' : 'none';
            });
            root.querySelectorAll('.tt-leases tr[data-lease]').forEach(function (tr) {
                tr.classList.toggle('tt-sel', tr.getAttribute('data-lease') === lease);
            });
            if (allLink) allLink.style.display = lease ? '' : 'none';
            var empty = root.querySelector('.tt-empty');
            if (empty) empty.style.display = shown ? 'none' : '';
        }
        root.querySelectorAll('.tt-leases tr[data-lease]').forEach(function (tr) {
            tr.addEventListener('click', function (e) {
                if (e.target.closest('a')) return;
                var l = tr.getAttribute('data-lease');
                lease = (lease === l) ? '' : l;
                apply();
            });
        });
        if (allLink) allLink.addEventListener('click', function (e) { e.preventDefault(); lease = ''; apply(); });
        root.querySelectorAll('.tt-f').forEach(function (b) {
            b.addEventListener('click', function () {
                cat = b.getAttribute('data-f');
                root.querySelectorAll('.tt-f').forEach(function (x) { x.classList.toggle('active', x === b); });
                apply();
            });
        });
        root.querySelectorAll('.tt-line').forEach(function (el) {
            el.addEventListener('click', function () {
                var more = el.nextElementSibling;
                if (more && more.classList.contains('tt-more')) more.classList.toggle('open');
            });
        });
    })();
    </script>
    <?php
}
