<?php
// Renewal / new-tenant charges report (read-only), run monthly by cron/monthly_reports/run.php.
// Same script as the local skill .claude/skills/renewal-charges-report.
// Renewal charges report: for one building and one month (or a range of months), check every residential renewal
// against the standard renewal terms - was each charge put on the lease, booked in the
// accounts, and received? Read-only. Writes a PDF.
//
// php renewal_charges_report.php "PARK PLACE" 2026-09 ~/Downloads/out.pdf [--all-leases]
// php renewal_charges_report.php "PARK PLACE" 2025-01:2026-10 ~/Downloads/out.pdf   (month by month)
//   --all-leases  include new tenants too (default: renewals only)
//   --new-tenants check NEW tenants instead, against the new-tenant terms (deposit 5%, commission 5% + VAT)
// Leases of 6 months or less (monthly extensions) are listed but not checked against the terms.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // command line only - never from a browser
require __DIR__ . '/../../vendor/autoload.php';

[$_, $buildingName, $month, $out] = $argv + [null, null, null, null];
$allLeases = in_array('--all-leases', $argv, true);
$NEW = in_array('--new-tenants', $argv, true);
$argv = array_values(array_filter($argv, fn($a) => !str_starts_with($a, '--')));
[$_, $buildingName, $month, $out] = $argv + [null, null, null, null];
if (!$buildingName || !preg_match('/^(\d{4}-\d{2})(?::(\d{4}-\d{2}))?$/', (string)$month, $mm) || !$out) {
    fwrite(STDERR, "usage: php renewal_charges_report.php \"BUILDING\" YYYY-MM[:YYYY-MM] out.pdf [--all-leases|--new-tenants]\n");
    exit(1);
}
$from = "{$mm[1]}-01";
$to   = date('Y-m-t', strtotime(($mm[2] ?? $mm[1]) . '-01'));
$multi = isset($mm[2]) && $mm[2] !== $mm[1];

// The standard renewal terms (from the renewal note). Edit here if the terms change.
$TERMS = [
    'rent'       => 'Not filled in on the note',
    'deposit'    => 'No (previous deposit carried forward)',
    'commission' => 'No',
    'chiller'    => 'Decided by P.M.',
    'chiller_vat'=> '5%',
    'ejari'      => 'AED 300',
    'parking'    => 'If any, P.M. to decide',
    'store'      => 'If any, P.M. to decide',
];
// New-tenant terms (from the "New Tenant (Residential Unit Only)" note). Order = order on the note.
if ($NEW) $TERMS = [
    'rent'           => 'Blank, not filled in',
    'deposit'        => '5% of rental value',
    'commission'     => '5% of rental value',
    'chiller'        => 'Depends on the P.M. or flat type',
    'commission_vat' => '5%',
    'chiller_vat'    => '5%',
    'parking'        => 'If any, P.M. decides',
    'store'          => 'If any, P.M. decides',
    'ejari'          => 'AED 300',
];
$W = $NEW
    ? ['title' => 'New Tenant Charges Check', 'what' => 'new tenants', 'terms' => 'new-tenant terms', 'note' => 'new-tenant note', 'skip' => 'Renewals (not included here - see the renewal report)', 'skipcol' => 'Renewals', 'none' => 'new tenants']
    : ['title' => 'Renewal Charges Check', 'what' => 'renewals', 'terms' => 'renewal terms', 'note' => 'renewal note', 'skip' => 'New tenants (not renewals, not included)', 'skipcol' => 'New tenants', 'none' => 'renewals'];
// Building-specific terms (single-building runs only). Ayla Residence new bookings, card "Ayla New Booking" (2026-10-24):
// rent, deposit 5%, commission 5% + VAT if not paid cash, Ejari 300, EV charger if it applies 700 + VAT per month.
// Ayla renewal card not received yet: renewals use the standard renewal terms plus the EV check.
$AYLA = strtoupper(trim((string)$buildingName)) === 'AYLA RESIDENCE';
if ($AYLA && $NEW) $TERMS = [
    'rent'           => 'Rent',
    'deposit'        => '5% of rent',
    'commission'     => '5% of rent',
    'commission_vat' => '5%, only if commission was not paid in cash',
    'ejari'          => 'AED 300',
    'ev'             => 'If it applies: AED 700 + VAT per month',
];
elseif ($AYLA) $TERMS['ev'] = 'If it applies: AED 700 + VAT per month (Ayla new-booking terms)';
const EV_MONTHLY = 700.0;
const NEW_DEPOSIT_PCT = 5.0;
const NEW_COMMISSION_PCT = 5.0;
const COMMISSION_VAT = 5.0;
const EJARI_FEE = 300.00;
const CHILLER_VAT = 5.0;
const CHILLER_VAT_FROM = '2026-02-01'; // VAT on chiller started here; earlier renewals are not flagged (user: "we already know")

// Database: local copy by default. The monthly job can point it at live via REPORT_DB_DSN / REPORT_DB_USER / REPORT_DB_PASS.
$pdo = new PDO(getenv('REPORT_DB_DSN') ?: 'mysql:unix_socket=/tmp/mysql.sock;dbname=u385648797_Mainsys;charset=utf8mb4',
    getenv('REPORT_DB_USER') ?: 'ainalreem', getenv('REPORT_DB_PASS') ?: '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = function ($sql, $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); };

// "ALL" = every building of every company that has leases (test/dev buildings left out)
$ALLB = strtoupper($buildingName) === 'ALL';
$blds = $ALLB
    ? $q("SELECT b.id, b.company_id, b.name FROM re_buildings b WHERE b.name NOT REGEXP 'test|dev' ORDER BY b.company_id, b.name")
    : $q("SELECT id, company_id, name FROM re_buildings WHERE name = ?", [$buildingName]);
if (!$blds) { fwrite(STDERR, "building not found: $buildingName\n"); exit(1); }
$b = $ALLB ? ['id' => 0, 'company_id' => 0, 'name' => 'All buildings, all companies'] : $blds[0];
$bIn = implode(',', array_map(fn($x) => (int)$x['id'], $blds));

$nonResidential = "'commercial','office','big_electrical_room','pump_room','store_room','dining_hall','big_kitchen','labour_room','labour_bathroom'";
$leases = $q("
    SELECT l.*, u.unit_number, u.unit_type, bb.name building_name,
           TRIM(CONCAT_WS(' ', t.first_name, t.last_name, t.company_name)) tenant
    FROM re_leases l JOIN re_units u ON u.id = l.unit_id JOIN re_buildings bb ON bb.id = u.building_id LEFT JOIN re_tenants t ON t.id = l.tenant_id
    WHERE u.building_id IN ($bIn) AND l.deleted_at IS NULL AND l.start_date BETWEEN ? AND ?
      AND u.unit_type NOT IN ($nonResidential)
    ORDER BY l.start_date, bb.name, u.unit_number", [$from, $to]);
// With several buildings, show the building with the unit everywhere
if ($ALLB) foreach ($leases as &$lx) { $lx['unit_number'] = $lx['building_name'] . ' · ' . $lx['unit_number']; } unset($lx);

// Bank accounts per company (leases carry their own company_id)
$bankIdsFor = function ($co) use ($q) { static $c = []; return $c[$co] ??= array_column($q("SELECT id FROM re_chart_of_accounts WHERE company_id = ? AND account_type = 'Asset'
    AND is_header = 0 AND account_code LIKE '12%' AND account_name NOT REGEXP 'card|receivable|suspense'", [$co]), 'id'); };

function m($v) { return number_format((float)$v, 2); }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$units = []; $skipped = []; $short = []; $legacy = [];
foreach ($leases as $l) {
    $ym = substr($l['start_date'], 0, 7);
    $prev = $q("SELECT * FROM re_leases WHERE unit_id = ? AND id <> ? AND start_date < ? AND deleted_at IS NULL
                ORDER BY start_date DESC LIMIT 1", [$l['unit_id'], $l['id'], $l['start_date']]);
    $prev = $prev[0] ?? null;
    $wf = $q("SELECT id, status FROM re_lease_renewal_workflows WHERE new_lease_id = ?", [$l['id']]);
    $isRenewal = $l['is_renewal_lease'] || $wf || ($prev && $prev['tenant_id'] == $l['tenant_id']);
    if ($NEW ? $isRenewal : (!$isRenewal && !$allLeases)) { $skipped[$ym][] = $l['unit_number']; continue; }
    if ((strtotime($l['end_date']) - strtotime($l['start_date'])) / 86400 <= 183) {
        $short[$ym][] = $l['unit_number'] . ' (' . date('j M', strtotime($l['start_date'])) . '–' . date('j M', strtotime($l['end_date'])) . ')';
        continue;
    }

    // Old accounting mode keeps charges outside re_obligations - cannot be checked the same way
    $nObl = (int)$q("SELECT COUNT(*) n FROM re_obligations WHERE lease_id = ?", [$l['id']])[0]['n'];
    if ($l['accounting_mode'] === 'legacy' || !$nObl) {
        $legacy[$ym][] = $l['unit_number'] . ($l['accounting_mode'] === 'legacy' ? ' (old accounting mode)' : ' (no charges in the system)');
        continue;
    }

    // Charges put on the lease (obligations), grouped into the renewal-note items
    $obs = $q("SELECT o.*, h.journal_number rj_no, h.is_reversed rj_rev FROM re_obligations o
               LEFT JOIN re_journal_headers h ON h.id = o.recognition_journal_id
               WHERE o.lease_id = ? AND o.status NOT IN ('cancelled')", [$l['id']]);
    $g = [];
    foreach ($obs as $o) {
        $d = strtolower($o['description']);
        $k = match (true) {
            $o['obligation_type'] === 'rent' => 'rent',
            $o['obligation_type'] === 'security_deposit' => 'deposit',
            $o['obligation_type'] === 'commission' => 'commission',
            (bool)preg_match('/\bev\b/i', $o['description']) => 'ev',
            str_contains($d, 'chiller') => 'chiller',
            str_contains($d, 'ejari') => 'ejari',
            $o['obligation_type'] === 'parking' || str_contains($d, 'parking') => 'parking',
            $o['obligation_type'] === 'store' || str_contains($d, 'store') => 'store',
            $o['obligation_type'] === 'admin_fee' => 'admin',
            default => 'other:' . $o['description'],
        };
        $g[$k] ??= ['sub' => 0, 'vat' => 0, 'tot' => 0, 'alloc' => 0, 'n' => 0, 'booked' => 0, 'journals' => [], 'waived' => 0];
        $g[$k]['sub'] += $o['subtotal_amount']; $g[$k]['vat'] += $o['vat_amount'];
        $g[$k]['tot'] += $o['total_amount']; $g[$k]['alloc'] += $o['allocated_amount']; $g[$k]['n']++;
        if ($o['status'] === 'waived') $g[$k]['waived']++;
        if ($o['rj_no'] && !$o['rj_rev']) { $g[$k]['booked']++; $g[$k]['journals'][] = $o['rj_no']; }
    }

    // Money received, and how it was booked
    $pays = $q("SELECT * FROM re_payments WHERE lease_id = ? ORDER BY payment_date, id", [$l['id']]);
    $issues = [];
    $recv = 0; $recvRows = [];
    foreach ($pays as $p) {
        $js = $q("SELECT h.id, h.journal_number, h.reference_type FROM re_journal_headers h
                  WHERE h.company_id = ? AND h.reference_id = ? AND h.is_reversed = 0
                    AND h.reference_type IN ('payment','security_deposit_receipt','tenant_credit')", [$l['company_id'], $p['id']]);
        $bankIds = $bankIdsFor($l['company_id']);
        $bankDr = 0;
        foreach ($js as $j) {
            if (!$bankIds) break;
            $in = implode(',', array_map('intval', $bankIds));
            $bankDr += (float)$q("SELECT COALESCE(SUM(debit_amount),0) v FROM re_journal_lines WHERE journal_id = ? AND account_id IN ($in)", [$j['id']])[0]['v'];
        }
        $ok = in_array($p['receipt_status'], ['cleared', 'received', 'posted', 'confirmed'], true) || $p['cleared_date'];
        if ($ok) $recv += $p['amount'];
        $recvRows[] = $p + ['journals' => array_column($js, 'journal_number'), 'bank_dr' => $bankDr];
        if ($bankDr - $p['amount'] > 0.009) {
            $issues[] = "Receipt {$p['receipt_number']} (AED " . m($p['amount']) . ") is booked into the bank as AED " . m($bankDr)
                . " across " . implode(' + ', array_column($js, 'journal_number')) . ". The bank looks overstated by AED " . m($bankDr - $p['amount']) . "."
                . (count($js) > 1 && in_array('security_deposit_receipt', array_column($js, 'reference_type'), true) ? " This is the known deposit double-post bug (deposit counted in the receipt journal and again in the deposit journal)." : '');
        }
        if (!$js) $issues[] = "Receipt {$p['receipt_number']} (AED " . m($p['amount']) . ") has no journal - not recorded in the accounts.";
        if ($p['cheque_id']) {
            // re_payments.cheque_id points at re_post_dated_cheques (the schedule lease_view shows)
            $c = $q("SELECT c.lease_id, l2.lease_number FROM re_post_dated_cheques c LEFT JOIN re_leases l2 ON l2.id = c.lease_id WHERE c.id = ?", [$p['cheque_id']]);
            if (!$c) {
                $issues[] = "Receipt {$p['receipt_number']} is linked to cheque record #{$p['cheque_id']}, which no longer exists (the cheque schedule was re-made).";
            } elseif ($c[0]['lease_id'] != $l['id']) {
                $issues[] = "Receipt {$p['receipt_number']} is linked to a cheque of another lease ({$c[0]['lease_number']}).";
            }
        }
    }
    $cheques = $q("SELECT cheque_number, cheque_date, cheque_amount, status FROM re_post_dated_cheques WHERE lease_id = ? ORDER BY cheque_date, id", [$l['id']]);
    $pendingPast = array_filter($cheques, fn($c) => $c['status'] === 'pending' && $c['cheque_date'] <= date('Y-m-d'));
    foreach ($pendingPast as $c) $issues[] = "Cheque {$c['cheque_number']} dated " . date('d M', strtotime($c['cheque_date'])) . " (AED " . m($c['cheque_amount']) . ") still shows pending on the lease's cheque schedule, so the lease page shows it as outstanding.";

    // Item-by-item verdicts against the renewal terms
    $rows = [];
    $mk = function ($key, $label, $charged, $booked, $received, $verdict, $note = '') use (&$rows, $TERMS) {
        $rows[] = compact('key', 'label', 'charged', 'booked', 'received', 'verdict', 'note') + ['term' => $TERMS[$key] ?? ''];
    };
    $jr = fn($k) => isset($g[$k]) && $g[$k]['journals'] ? $g[$k]['journals'][0] . ($g[$k]['booked'] > 1 ? " +" . ($g[$k]['booked'] - 1) : '') : '';
    $bookedTxt = fn($k) => isset($g[$k]) ? ($g[$k]['booked'] ? "Yes · {$g[$k]['booked']} of {$g[$k]['n']} periods<br><span class='s'>" . $jr($k) . "</span>" : 'Not yet') : '—';
    $allocTxt = fn($k) => isset($g[$k]) ? ($g[$k]['alloc'] >= $g[$k]['tot'] - 0.01 ? 'Fully' : ($g[$k]['alloc'] > 0 ? 'AED ' . m($g[$k]['alloc']) . ' so far' : 'Not yet')) : '—';

    // 1 Rent
    $rentNote = 'AED ' . m($l['annual_rent']) . ' / year · ' . (int)$l['number_of_installments'] . ' cheques';
    if ($prev && $prev['annual_rent'] > 0 && $prev['start_date'] < $l['start_date'] && strtotime($prev['end_date']) - strtotime($prev['start_date']) > 300 * 86400) {
        $rentNote .= '<br><span class="s">Previous: AED ' . m($prev['annual_rent']) . ' (' . sprintf('%+.1f', ($l['annual_rent'] / $prev['annual_rent'] - 1) * 100) . '%)</span>';
    }
    $firstRentDue = $q("SELECT MIN(due_date) d FROM re_obligations WHERE lease_id = ? AND obligation_type = 'rent'", [$l['id']])[0]['d'];
    $rentNotDue = isset($g['rent']) && !$g['rent']['booked'] && $firstRentDue >= date('Y-m-01');
    $mk('rent', 'Rent', $rentNote, $rentNotDue ? 'Not yet (first rent due ' . date('j M', strtotime($firstRentDue)) . ')' : $bookedTxt('rent'), 'AED ' . m($recv) . ' received in total<br><span class="s">' . count(array_filter($cheques, fn($c) => $c['status'] === 'cleared')) . ' of ' . count($cheques) . ' cheques cleared</span>',
        isset($g['rent']) && $g['rent']['booked'] ? 'ok' : ($rentNotDue ? 'info' : 'warn'), $rentNotDue ? 'Due this month - check it gets booked.' : '');

    if ($NEW) {
        // 2 Security deposit - new tenant: 5% of the yearly rent
        $want = round($l['annual_rent'] * NEW_DEPOSIT_PCT / 100, 2);
        $dep = isset($g['deposit']) ? $g['deposit']['tot'] : (float)$l['security_deposit'];
        $okDep = $dep > 0 && abs($dep - $want) <= 1;
        $mk('deposit', 'Security deposit', ($dep > 0 ? 'AED ' . m($dep) : 'None charged') . '<br><span class="s">5% of rent = AED ' . m($want) . '</span>',
            isset($g['deposit']) ? ($g['deposit']['alloc'] > 0 ? 'Yes · deposit liability' : 'Not yet') : '—', $allocTxt('deposit'),
            $okDep ? 'ok' : 'warn', $okDep ? '' : ($dep > 0 ? 'Not 5% of rent.' : 'No deposit charged.'));
        // 3 Commission - new tenant: 5% of the yearly rent
        $want = round($l['annual_rent'] * NEW_COMMISSION_PCT / 100, 2);
        $com = isset($g['commission']) ? $g['commission']['sub'] : (float)$l['commission_fees'];
        $okCom = $com > 0 && abs($com - $want) <= 1;
        $mk('commission', 'Commission', ($com > 0 ? 'AED ' . m($com) : 'None charged') . '<br><span class="s">5% of rent = AED ' . m($want) . '</span>',
            $bookedTxt('commission'), $allocTxt('commission'), $okCom ? 'ok' : 'warn', $okCom ? '' : ($com > 0 ? 'Not 5% of rent.' : 'No commission charged.'));
        // 5 VAT on commission
        if ($com > 0 && $AYLA) {
            // Ayla: VAT only when the commission was NOT paid in cash. How was it paid?
            $meth = array_column($q("SELECT DISTINCT p.payment_method m FROM re_obligations o
                JOIN re_receipt_allocations a ON (a.obligation_id = o.id OR (o.invoice_id IS NOT NULL AND a.invoice_id = o.invoice_id))
                JOIN re_payments p ON p.id = a.payment_id WHERE o.lease_id = ? AND o.obligation_type = 'commission'", [$l['id']]), 'm');
            $judged = false;
            if (!$meth) {
                // Usually paid through tenant credit, so no direct link: judge from the move-in payments
                // (receipts from 30 days before to 15 days after the lease start).
                $meth = array_column($q("SELECT DISTINCT payment_method m FROM re_payments WHERE lease_id = ?
                    AND payment_date BETWEEN DATE_SUB(?, INTERVAL 30 DAY) AND DATE_ADD(?, INTERVAL 15 DAY)", [$l['id'], $l['start_date'], $l['start_date']]), 'm');
                $judged = (bool)$meth;
            }
            $cv = isset($g['commission']) ? $g['commission']['vat'] : 0;
            $rate = $cv / $com * 100; $has5 = abs($rate - COMMISSION_VAT) < 0.1;
            $paidTxt = $meth ? ($judged ? 'Move-in paid by ' : 'Paid by ') . implode(' + ', array_map(fn($x) => str_replace('_', ' ', $x), $meth)) : 'No payment found';
            if ($meth && !array_diff($meth, ['cash'])) {
                $v = $cv == 0 ? 'ok' : 'info'; $note = $cv == 0 ? 'Paid in cash - no VAT needed.' : 'Paid in cash but VAT was charged.';
            } elseif ($meth) {
                $v = $has5 ? 'ok' : 'warn'; $note = $has5 ? '' : 'Not paid in cash - 5% VAT needed.';
            } else {
                $v = $has5 ? 'ok' : 'info'; $note = $has5 ? '' : 'Cannot tell if paid in cash - VAT needed unless cash.';
            }
            $mk('commission_vat', 'VAT on commission', 'AED ' . m($cv) . ' (' . number_format($rate, 1) . '%)<br><span class="s">' . h($paidTxt) . '</span>', $cv > 0 ? ($g['commission']['booked'] ? 'Yes · Output VAT' : 'Not yet') : '—', $cv > 0 ? $allocTxt('commission') : '—', $v, $note);
        } elseif ($com > 0) {
            $cv = isset($g['commission']) ? $g['commission']['vat'] : 0;
            $rate = $cv / $com * 100;
            $okV = abs($rate - COMMISSION_VAT) < 0.1;
            $mk('commission_vat', 'VAT on commission', 'AED ' . m($cv) . ' (' . number_format($rate, 1) . '%)', $cv > 0 ? ($g['commission']['booked'] ? 'Yes · Output VAT' : 'Not yet') : '—', $cv > 0 ? $allocTxt('commission') : '—',
                $okV ? 'ok' : 'warn', $okV ? '' : ($rate == 0 ? 'No VAT charged.' : 'VAT is not 5%.'));
        } else {
            $mk('commission_vat', 'VAT on commission', '—', '—', '—', 'na');
        }
    } else {
        // 2 Security deposit - renewal: previous deposit carried forward, no new deposit expected
        $prevDep = $prev ? (float)$prev['security_deposit'] : 0;
        if (isset($g['deposit'])) {
            $note = 'New deposit of AED ' . m($g['deposit']['tot']) . ' charged';
            if ($prevDep > 0) $note .= '<br><span class="s">Previous lease held AED ' . m($prevDep) . '</span>';
            $mk('deposit', 'Security deposit', $note, $g['deposit']['alloc'] > 0 ? 'Yes' : 'Not yet', $allocTxt('deposit'), 'warn', 'Terms say carry the old deposit forward. Check that only the top-up was collected.');
        } else {
            $mk('deposit', 'Security deposit', 'None charged' . ($prevDep > 0 ? '<br><span class="s">AED ' . m($prevDep) . ' carried forward</span>' : ''), '—', '—', 'ok');
        }
        // 3 Commission
        $com = (float)$l['commission_fees'] + (isset($g['commission']) ? $g['commission']['tot'] : 0);
        $mk('commission', 'Commission', $com > 0 ? 'AED ' . m($com) . ' charged' : 'None charged', $bookedTxt('commission'), $allocTxt('commission'), $com > 0 ? 'warn' : 'ok', $com > 0 ? 'Terms say no commission on renewal.' : '');
    }
    // 4 Chiller
    if (isset($g['chiller'])) {
        $mk('chiller', 'Chiller', 'AED ' . m($g['chiller']['sub']) . ($g['chiller']['n'] > 1 ? " · split over {$g['chiller']['n']} months" : ' · upfront'), $bookedTxt('chiller'), $allocTxt('chiller'), 'ok');
    } else {
        $mk('chiller', 'Chiller', 'None charged', '—', '—', 'info', 'P.M. decides - confirm none was intended.');
    }
    // 5 VAT on chiller
    if (isset($g['chiller'])) {
        $rate = $g['chiller']['sub'] > 0 ? $g['chiller']['vat'] / $g['chiller']['sub'] * 100 : 0;
        if ($l['start_date'] < CHILLER_VAT_FROM && $rate == 0) {
            $mk('chiller_vat', 'VAT on chiller', 'None (before VAT on chiller started, ' . date('M Y', strtotime(CHILLER_VAT_FROM)) . ')', '—', '—', 'na');
        } else
        $mk('chiller_vat', 'VAT on chiller', 'AED ' . m($g['chiller']['vat']) . ' (' . number_format($rate, 1) . '%)', $g['chiller']['vat'] <= 0 ? '—' : ($g['chiller']['booked'] ? 'Yes · Output VAT' : 'Not yet'), $g['chiller']['vat'] <= 0 ? '—' : $allocTxt('chiller'),
            abs($rate - CHILLER_VAT) < 0.1 ? 'ok' : 'warn', abs($rate - CHILLER_VAT) < 0.1 ? '' : ($rate == 0 ? 'No VAT charged.' : 'VAT is not 5%.'));
    } else {
        $mk('chiller_vat', 'VAT on chiller', '—', '—', '—', 'na');
    }
    // 6 Ejari
    if (isset($g['ejari'])) {
        $right = abs($g['ejari']['tot'] - EJARI_FEE) < 0.01;
        $mk('ejari', 'Ejari', 'AED ' . m($g['ejari']['tot']), $bookedTxt('ejari'), $allocTxt('ejari'), $right ? 'ok' : 'warn', $right ? '' : 'Terms say AED 300.');
    } else {
        $mk('ejari', 'Ejari', 'Not charged', '—', '—', 'warn', 'Terms say AED 300.');
    }
    // 7 / 8 Parking and store - P.M. decides
    foreach ([['parking', 'Extra parking', 'has_additional_parking', 'additional_parking_fee'], ['store', 'Extra store room', 'has_additional_store', 'additional_store_fee']] as [$k, $lab, $flag, $fee]) {
        if (isset($g[$k]) || $l[$flag]) {
            $amt = isset($g[$k]) ? $g[$k]['tot'] : (float)$l[$fee];
            $mk($k, $lab, 'AED ' . m($amt), $bookedTxt($k), $allocTxt($k), isset($g[$k]) && $g[$k]['booked'] ? 'ok' : 'warn');
        } else {
            $mk($k, $lab, 'None', '—', '—', 'na');
        }
    }
    // EV charger (Ayla): if charged, AED 700 + VAT = 735 per month
    if (isset($TERMS['ev'])) {
        if (isset($g['ev'])) {
            $per = $g['ev']['tot'] / max(1, $g['ev']['n']); $okEv = abs($per - EV_MONTHLY * 1.05) <= 1; $vatSep = $g['ev']['vat'] > 0;
            $mk('ev', 'EV charger', 'AED ' . m($per) . ' × ' . $g['ev']['n'] . ' month(s) = AED ' . m($g['ev']['tot']) . ($vatSep ? '' : '<br><span class="s">No VAT booked separately</span>'),
                $bookedTxt('ev'), $allocTxt('ev'), $okEv && $vatSep ? 'ok' : 'warn',
                $okEv ? ($vatSep ? '' : 'Amount right, but VAT not booked separately.') : 'Should be AED 735/month (700 + VAT).');
        } else {
            $mk('ev', 'EV charger', 'None', '—', '—', 'na');
        }
    }
    // Only the items on this building's note get a row; anything else charged is listed as an extra
    $rows = array_values(array_filter($rows, fn($r) => isset($TERMS[$r['key']])));
    $order = array_flip(array_keys($TERMS));
    usort($rows, fn($x, $y) => ($order[$x['key']] ?? 99) <=> ($order[$y['key']] ?? 99));
    // Anything else charged that is not on the renewal note
    $extras = [];
    foreach ($g as $k => $v) {
        if (isset($TERMS[$k])) continue;
        $name = $k === 'admin' ? 'Admin fee' : (str_starts_with($k, 'other:') ? substr($k, 6) : ucfirst($k));
        $extras[] = "$name: AED " . m($v['sub']) . ($v['vat'] > 0 ? ' + VAT ' . m($v['vat']) : '') . ' · ' . ($v['booked'] ? 'booked ' . $jr($k) : 'not booked') . ' · ' . $allocTxt($k) . ' received';
    }

    $units[] = ['ym' => $ym, 'l' => $l, 'prev' => $prev, 'wf' => $wf, 'rows' => $rows, 'issues' => $issues, 'extras' => $extras, 'recv' => $recvRows];
}

// ---------- PDF ----------
$css = <<<CSS
body { font-family: dejavusans; font-size: 8.5pt; color: #1f2933; }
h1 { font-size: 19pt; color: #0b3d5c; margin: 0; }
h2 { font-size: 13pt; color: #0b3d5c; margin: 0; }
.sub { color: #5f6b7a; font-size: 9pt; }
.s { color: #5f6b7a; font-size: 7pt; }
table { border-collapse: collapse; width: 100%; }
th { background: #0b3d5c; color: #fff; padding: 6px; text-align: left; font-size: 8pt; }
td { padding: 6px; border-bottom: 1px solid #e3e8ee; vertical-align: top; }
.ok { background: #0b6e4f; color: #fff; text-align: center; font-weight: bold; }
.warn { background: #f4b740; color: #3d2a00; text-align: center; font-weight: bold; }
.info { background: #c9d3dd; color: #24313d; text-align: center; font-weight: bold; }
.na { color: #9aa5b1; text-align: center; }
.head td { border: none; padding: 0 0 8px 0; }
.box { border-left: 4px solid #b42318; background: #fdf1f0; padding: 7px 10px; margin-top: 8px; }
.box.amb { border-left-color: #d4a017; background: #fff8e6; }
.box.grn { border-left-color: #0b6e4f; background: #eaf6ef; }
.kv td { border: none; padding: 1px 10px 1px 0; }
CSS;
$label = ['ok' => '✓ OK', 'warn' => '⚠ CHECK', 'info' => 'CONFIRM', 'na' => '—'];
$monthTxt = $multi ? date('M Y', strtotime($from)) . ' – ' . date('M Y', strtotime($to)) : date('F Y', strtotime($from));
$keyLabels = ['rent' => 'Rent', 'deposit' => 'Deposit', 'commission' => 'Commission', 'chiller' => 'Chiller', 'commission_vat' => 'Comm. VAT', 'ev' => 'EV', 'chiller_vat' => 'Chiller VAT', 'ejari' => 'Ejari', 'parking' => 'Parking', 'store' => 'Store'];
$keys = array_intersect_key($keyLabels, $TERMS);
$keys = array_merge(array_intersect_key($TERMS, $keys), $keys); // keep the note's order
$checks = fn($u) => count(array_filter($u['rows'], fn($r) => $r['verdict'] === 'warn'));

$grid = function ($list, $label) use ($keys, $W) {
    $g = "<table><tr><th>Unit</th><th>Tenant</th><th>Start</th>";
    foreach ($keys as $v) $g .= "<th style='text-align:center'>$v</th>";
    $g .= "<th style='text-align:center'>Recording problems</th></tr>";
    foreach ($list as $u) {
        $g .= "<tr><td><b>" . h($u['l']['unit_number']) . "</b></td><td>" . h($u['l']['tenant']) . "</td><td>" . date('j M', strtotime($u['l']['start_date'])) . "</td>";
        $by = array_column($u['rows'], 'verdict', 'key');
        foreach ($keys as $k => $_) $g .= "<td class='{$by[$k]}' style='font-size:7pt'>{$label[$by[$k]]}</td>";
        $n = count($u['issues']);
        $g .= "<td class='" . ($n ? 'warn' : 'ok') . "'>" . ($n ?: '0') . "</td></tr>";
    }
    return $g . "</table><p class='s'><b>CHECK</b> in a charge column = the charge does not match the {$W['terms']}. <b>Recording problems</b> = how the money was recorded (a paid cheque still pending, a receipt linked to the wrong cheque, the bank booked twice) - the details are on the unit's page.</p>";
};
$monthNotes = function ($ym) use ($skipped, $short, $legacy, $W) {
    $t = '';
    if (!empty($legacy[$ym])) $t .= "<p class='s'>Not checked: " . h(implode(', ', $legacy[$ym])) . ".</p>";
    if (!empty($short[$ym])) $t .= "<p class='s'>Monthly extensions (not checked against the yearly {$W['terms']}): " . h(implode(', ', $short[$ym])) . ".</p>";
    if (!empty($skipped[$ym])) $t .= "<p class='s'>{$W['skip']}: " . h(implode(', ', $skipped[$ym])) . ".</p>";
    return $t;
};

$h = "<style>$css</style>";
$h .= "<h1>{$W['title']}</h1><div class='sub'>" . h($b['name']) . " · residential {$W['what']} starting " . ($multi ? "month by month, $monthTxt" : "in $monthTxt") . " · data as of " . date('j M Y') . "</div><br>";

$byMonth = [];
foreach ($units as $u) $byMonth[$u['ym']][] = $u;
$months = [];
for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 month', $t)) $months[] = date('Y-m', $t);

if ($multi) {
    $h .= "<table><tr><th>Month</th><th style='text-align:center'>" . ucfirst($W['what']) . " checked</th><th style='text-align:center'>All items OK</th><th style='text-align:center'>Items to check</th><th style='text-align:center'>Recording problems</th><th style='text-align:center'>Monthly extensions</th><th style='text-align:center'>{$W['skipcol']}</th><th>Units with something to check</th></tr>";
    $T = [0, 0, 0, 0, 0, 0];
    foreach ($months as $ym) {
        $list = $byMonth[$ym] ?? [];
        $clean = count(array_filter($list, fn($u) => !$checks($u) && !$u['issues']));
        $ck = array_sum(array_map($checks, $list)); $is = array_sum(array_map(fn($u) => count($u['issues']), $list));
        $sh = count($short[$ym] ?? []); $nt = count($skipped[$ym] ?? []);
        $bad = array_map(fn($u) => $u['l']['unit_number'], array_filter($list, fn($u) => $checks($u) || $u['issues']));
        foreach ([count($list), $clean, $ck, $is, $sh, $nt] as $i => $v) $T[$i] += $v;
        $h .= "<tr><td><b>" . date('M Y', strtotime("$ym-01")) . "</b></td><td style='text-align:center'>" . count($list) . "</td><td style='text-align:center'>$clean</td>"
            . "<td class='" . ($ck ? 'warn' : 'na') . "'>" . ($ck ?: '—') . "</td><td class='" . ($is ? 'warn' : 'na') . "'>" . ($is ?: '—') . "</td>"
            . "<td style='text-align:center'>" . ($sh ?: '—') . "</td><td style='text-align:center'>" . ($nt ?: '—') . "</td><td>" . h(implode(', ', $bad)) . "</td></tr>";
    }
    $h .= "<tr style='font-weight:bold;background:#dfe9f1'><td>Total</td>" . implode('', array_map(fn($v) => "<td style='text-align:center'>$v</td>", array_slice($T, 0, 4))) . "<td style='text-align:center'>{$T[4]}</td><td style='text-align:center'>{$T[5]}</td><td></td></tr></table>";

    // What the checks found, by item
    $tally = [];
    foreach ($units as $u) foreach ($u['rows'] as $r) if ($r['verdict'] === 'warn') $tally[$r['label']][] = $u['l']['unit_number'] . ' (' . date('M y', strtotime($u['l']['start_date'])) . ')';
    $itype = [];
    foreach ($units as $u) foreach ($u['issues'] as $iss) {
        $k = str_contains($iss, 'overstated') ? 'Bank booked twice (overstated)' : (str_contains($iss, 'no longer exists') || str_contains($iss, 'another lease') ? 'Receipt linked to wrong / deleted cheque' : (str_contains($iss, 'pending') ? 'Paid cheque still shows pending' : 'Receipt not recorded'));
        $itype[$k][$u['l']['unit_number'] . ' (' . date('M y', strtotime($u['l']['start_date'])) . ')'] = true;
    }
    $h .= "<br><table style='width:100%'><tr><td style='width:50%;vertical-align:top;border:none;padding:0 8px 0 0'><table><tr><th>Item flagged CHECK</th><th style='text-align:center'>Times</th><th>Units</th></tr>";
    foreach ($tally as $k => $v) $h .= "<tr><td>" . h($k) . "</td><td style='text-align:center'>" . count($v) . "</td><td class='s'>" . h(implode(', ', $v)) . "</td></tr>";
    if (!$tally) $h .= "<tr><td colspan='3'>None</td></tr>";
    $h .= "</table></td><td style='width:50%;vertical-align:top;border:none;padding:0 0 0 8px'><table><tr><th>Recording problem</th><th style='text-align:center'>Units</th><th>Which</th></tr>";
    foreach ($itype as $k => $v) $h .= "<tr><td>" . h($k) . "</td><td style='text-align:center'>" . count($v) . "</td><td class='s'>" . h(implode(', ', array_keys($v))) . "</td></tr>";
    if (!$itype) $h .= "<tr><td colspan='3'>None</td></tr>";
    $h .= "</table></td></tr></table>";
}
if ($multi) {
    $nLegacy = array_sum(array_map('count', $legacy));
    if ($nLegacy) $h .= "<p class='s'>$nLegacy lease(s) use the old accounting mode or have no charges in the system yet, so they are listed per month but not checked.</p>";
}
if ($ALLB) {
    $byB = [];
    foreach ($units as $u) { $bn = $u['l']['building_name']; $byB[$bn][] = $u; }
    ksort($byB);
    $h .= "<br><table><tr><th>Building</th><th style='text-align:center'>" . ucfirst($W['what']) . " checked</th><th style='text-align:center'>All items OK</th><th style='text-align:center'>Items to check</th><th style='text-align:center'>Recording problems</th></tr>";
    foreach ($byB as $bn => $list) {
        $ck = array_sum(array_map($checks, $list)); $is = array_sum(array_map(fn($u) => count($u['issues']), $list));
        $clean = count(array_filter($list, fn($u) => !$checks($u) && !$u['issues']));
        $h .= "<tr><td><b>" . h($bn) . "</b></td><td style='text-align:center'>" . count($list) . "</td><td style='text-align:center'>$clean</td><td class='" . ($ck ? 'warn' : 'na') . "'>" . ($ck ?: '—') . "</td><td class='" . ($is ? 'warn' : 'na') . "'>" . ($is ?: '—') . "</td></tr>";
    }
    $h .= "</table><p class='s'>The same standard terms are applied to every building.</p>";
}
$h .= "<p class='s'>The {$W['terms']} each charge is checked against: " . implode(' · ', array_map(fn($k, $v) => "<b>{$keys[$k]}</b>: " . h($v), array_keys($TERMS), $TERMS)) . "</p>";

foreach ($months as $ym) {
  $list = $byMonth[$ym] ?? [];
  if ($multi) {
      if (!$list && empty($short[$ym]) && empty($skipped[$ym]) && empty($legacy[$ym])) continue;
      $h .= "<pagebreak /><h1 style='font-size:16pt'>" . date('F Y', strtotime("$ym-01")) . "</h1><div class='sub'>" . count($list) . " lease(s) checked</div><br>";
  }
  if ($list) $h .= $grid($list, $label);
  elseif (!$multi) $h .= "<p>No residential {$W['none']} started in $monthTxt.</p>";
  $h .= $monthNotes($ym);
  foreach ($list as $u) {
    $l = $u['l'];
    $h .= "<pagebreak />";
    $how = $NEW ? 'New tenant' : ($u['wf'] ? 'Renewal workflow #' . $u['wf'][0]['id'] : 'Same tenant, new lease (not through the renewal workflow)');
    $h .= "<table class='head'><tr><td><h2>Unit " . h($l['unit_number']) . " · " . h($l['tenant']) . "</h2>
        <table class='kv'><tr><td class='s'>Lease</td><td>" . h($l['lease_number']) . "</td><td class='s'>Period</td><td>" . date('j M Y', strtotime($l['start_date'])) . " – " . date('j M Y', strtotime($l['end_date'])) . "</td></tr>
        <tr><td class='s'>Type</td><td>" . h(strtoupper($l['unit_type'])) . "</td><td class='s'>" . ($NEW ? 'Tenant' : 'How renewed') . "</td><td>" . h($how) . "</td></tr>"
        . ($u['prev'] ? "<tr><td class='s'>Previous lease</td><td colspan='3'>" . h($u['prev']['lease_number']) . " · " . date('j M Y', strtotime($u['prev']['start_date'])) . " – " . date('j M Y', strtotime($u['prev']['end_date'])) . "</td></tr>" : '')
        . "</table></td></tr></table>";
    $h .= "<table><tr><th style='width:3%'>#</th><th style='width:13%'>Item</th><th style='width:16%'>Term on the note</th><th style='width:21%'>Charged on lease</th><th style='width:17%'>Booked in accounts</th><th style='width:17%'>Received</th><th style='width:13%;text-align:center'>Check</th></tr>";
    foreach ($u['rows'] as $i => $r) {
        $h .= "<tr><td>" . ($i + 1) . "</td><td><b>{$r['label']}</b></td><td class='s'>" . h($r['term']) . "</td><td>{$r['charged']}</td><td>{$r['booked']}</td><td>{$r['received']}</td><td class='{$r['verdict']}'>{$label[$r['verdict']]}" . ($r['note'] ? "<div style='font-weight:normal;font-size:6.5pt'>" . h($r['note']) . "</div>" : '') . "</td></tr>";
    }
    $h .= "</table>";
    if ($u['extras']) $h .= "<div class='box amb'><b>Charged but not on the {$W['note']}</b><br>" . implode('<br>', array_map('h', $u['extras'])) . "</div>";
    if (trim((string)$l['notes']) !== '') $h .= "<div class='box amb'><b>Note on the lease</b><br>" . nl2br(h($l['notes'])) . "</div>";
    $h .= $u['issues'] ? "<div class='box'><b>Recording problems</b><br>• " . implode('<br>• ', array_map('h', $u['issues'])) . "</div>"
                       : "<div class='box grn'><b>No recording problems found.</b></div>";
    // receipts
    if ($u['recv']) {
        $h .= "<br><table><tr><th>Receipt</th><th>Date</th><th>Method</th><th style='text-align:right'>Amount</th><th>Status</th><th>Journal(s)</th></tr>";
        foreach ($u['recv'] as $p) $h .= "<tr><td>" . h($p['receipt_number']) . "</td><td>" . date('d M Y', strtotime($p['payment_date'])) . "</td><td>" . h(str_replace('_', ' ', $p['payment_method'])) . "</td><td style='text-align:right'>" . m($p['amount']) . "</td><td>" . h($p['receipt_status']) . "</td><td>" . h(implode(', ', $p['journals']) ?: '—') . "</td></tr>";
        $h .= "</table>";
    }
  }
}

$mpdf = new \Mpdf\Mpdf(['format' => 'A4-L', 'margin_top' => 12, 'margin_bottom' => 18, 'margin_left' => 12, 'margin_right' => 12, 'tempDir' => sys_get_temp_dir()]);
$mpdf->SetTitle($W['title']);
$mpdf->SetFooter(h($b['name']) . " · {$W['what']} $monthTxt||Page {PAGENO} of {nbpg}");
$mpdf->WriteHTML($h);
$mpdf->Output($out, \Mpdf\Output\Destination::FILE);
echo "OK " . count($units) . " {$W['what']} checked; {$W['skipcol']}: " . array_sum(array_map('count', $skipped)) . "; monthly extensions: " . array_sum(array_map('count', $short)) . "; not checked (old mode): " . array_sum(array_map('count', $legacy)) . "\n";
