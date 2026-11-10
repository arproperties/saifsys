<?php
declare(strict_types=1);

/**
 * Tenancy Contract v2: render rules, Annex A due-date check, unfilled-placeholder check,
 * and a full render of modules/realestate/contract_templates/tenancy_contract_v2.html.
 *
 * Reads the local database only for the company name; writes nothing.
 *
 *     php tests/contract_v2_test.php
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/company_helper.php';
require_once __DIR__ . '/../modules/realestate/includes/contract_v2_helper.php';
require_once __DIR__ . '/../modules/realestate/includes/contract_pdf_generator.php';

$failures = 0;
function check(string $what, $expected, $actual): void
{
    global $failures;
    if ($expected === $actual) {
        echo "ok   - {$what}\n";
        return;
    }
    $failures++;
    echo "FAIL - {$what}\n       expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
}
function throws(callable $fn, string $contains = ''): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $contains === '' || strpos($e->getMessage(), $contains) !== false;
    }
    return false;
}

// --- number words -------------------------------------------------------------
check('500 in words', 'Five Hundred', contract_v2_number_words(500));
check('7 in words', 'Seven', contract_v2_number_words(7));
check('1250 in words', 'One Thousand Two Hundred Fifty', contract_v2_number_words(1250));
check('45 in words', 'Forty-Five', contract_v2_number_words(45));
check('100000 in words', 'One Hundred Thousand', contract_v2_number_words(100000));
check('Arabic digits', '٣٣', contract_v2_arabic_digits(33));

// --- {{#if}} blocks -----------------------------------------------------------
check('if true keeps block', 'a[X]b', contract_v2_render_conditionals('a{{#if F}}[X]{{/if}}b', ['F' => true]));
check('if false drops block', 'ab', contract_v2_render_conditionals('a{{#if F}}[X]{{/if}}b', ['F' => false]));
check('else branch', 'a[Y]b', contract_v2_render_conditionals('a{{#if F}}[X]{{else}}[Y]{{/if}}b', ['F' => false]));
check('unknown flag throws', true, throws(fn() => contract_v2_render_conditionals('{{#if NOPE}}x{{/if}}', []), 'unknown flag'));
check('unmatched if throws', true, throws(fn() => contract_v2_render_conditionals('{{#if F}}x', ['F' => true]), 'unmatched'));

// --- clause numbering ---------------------------------------------------------
check('numbers follow order, AR and REF repeat current',
    '1.١[1] 2.٢[2]',
    contract_v2_number_clauses('{{CLAUSE_NO}}.{{CLAUSE_NO_AR}}[{{CLAUSE_REF}}] {{CLAUSE_NO}}.{{CLAUSE_NO_AR}}[{{CLAUSE_REF}}]'));

// --- unfilled placeholder check -----------------------------------------------
check('clean html passes', false, throws(fn() => contract_v2_assert_resolved('<p>All filled</p>')));
check('leftover placeholder throws and names it', true,
    throws(fn() => contract_v2_assert_resolved('<p>{{TENANT_NAME}} and {{ FOO }}</p>'), 'TENANT_NAME, FOO'));

// --- Annex A due-date rule ----------------------------------------------------
$sched = fn(array $dates) => array_map(fn($d, $i) => ['instalment_no' => $i + 1, 'due_date' => $d], $dates, array_keys($dates));
check('4 cheques on the start day: no warnings', [],
    contract_v2_validate_schedule($sched(['2026-12-15', '2027-03-15', '2027-06-15', '2027-09-15']), '2026-12-15'));
check('first cheque on the 20th: wrong date and wrong day, two warnings', 2,
    count(contract_v2_validate_schedule($sched(['2026-12-20', '2027-03-15']), '2026-12-15')));
check('first cheque on start day of another month: one warning', 1,
    count(contract_v2_validate_schedule($sched(['2027-01-15', '2027-04-15']), '2026-12-15')));
check('cheque on another day of month: warned', 1,
    count(contract_v2_validate_schedule($sched(['2026-12-15', '2027-03-16']), '2026-12-15')));
check('start on 31st, cheque on 28 Feb: month end allowed', [],
    contract_v2_validate_schedule($sched(['2027-01-31', '2027-02-28', '2027-03-31']), '2027-01-31'));
check('override silences warnings', [],
    contract_v2_validate_schedule($sched(['2026-12-20', '2027-03-16']), '2026-12-15', true));

$ns = contract_v2_number_schedule([
    ['due_date' => '2027-01-01'], ['due_date' => '2027-02-01'], ['due_date' => '2027-02-01'], ['due_date' => '2027-03-01'],
], '2027-03-31');
check('two cheques on one date: same period, not the lease end', ['2027-02-28', '2027-02-28', '2027-03-31'],
    [$ns[1]['period_to'], $ns[2]['period_to'], $ns[3]['period_to']]);

// --- max occupants from the unit ----------------------------------------------
check('studio = 2', 2, contract_v2_compute_max_occupants(0, null)['value']);
check('1BR, 14 sqm bedroom = 2 (area limit binds)', 2, contract_v2_compute_max_occupants(1, 14)['value']);
check('2BR, 30 sqm bedrooms = 6', 6, contract_v2_compute_max_occupants(2, 30)['value']);
check('2BR, no bedroom area = 6', 6, contract_v2_compute_max_occupants(2, null)['value']);
check('1BR, no area = 4', 4, contract_v2_compute_max_occupants(1, null)['value']);
check('5BR uses 2b+3 cap: 12', 12, contract_v2_compute_max_occupants(5, null)['value']);
check('basis text', '2BR, 30 sqm bedrooms → 6', contract_v2_compute_max_occupants(2, 30)['basis']);
check('no bedrooms: refused', true, throws(fn() => contract_v2_compute_max_occupants(null, 20), 'no bedrooms'));
$u2 = ['bedrooms' => 2, 'bedroom_area_sqm' => 30];
$r = contract_v2_resolve_lease_occupancy(null, 7, $u2, false, false, null, '');
check('new lease: calculated 6', [6, null], [$r['max_occupants'], $r['override_reason']]);
$r = contract_v2_resolve_lease_occupancy(['unit_id' => 7, 'max_occupants' => 5, 'max_occupants_basis' => 'old', 'max_occupants_override_reason' => null], 7, $u2, false, false, null, '');
check('existing lease, same unit: snapshot kept (5)', 5, $r['max_occupants']);
$r = contract_v2_resolve_lease_occupancy(['unit_id' => 3, 'max_occupants' => 5, 'max_occupants_override_reason' => null], 7, $u2, false, false, null, '');
check('unit changed: recalculated (6)', 6, $r['max_occupants']);
$r = contract_v2_resolve_lease_occupancy(null, 7, $u2, true, true, 8, '');
check('override without reason: refused', true, strpos((string)$r['error'], 'reason') !== false);
$r = contract_v2_resolve_lease_occupancy(null, 7, $u2, true, true, 8, 'Family of 8, DM letter');
check('override 8 saved with warning', [8, true], [$r['max_occupants'], strpos((string)$r['warning'], 'cap of 6') !== false]);
$r = contract_v2_resolve_lease_occupancy(null, 7, $u2, false, true, 8, 'x');
check('non-Admin cannot override', 6, $r['max_occupants']);
$r = contract_v2_resolve_lease_occupancy(['unit_id' => 7, 'max_occupants' => 8, 'max_occupants_basis' => 'Admin override: 8', 'max_occupants_override_reason' => 'Family of 8'], 7, $u2, false, false, null, '');
check('non-Admin save keeps Admin override', [8, 'Family of 8'], [$r['max_occupants'], $r['override_reason']]);
$r = contract_v2_resolve_lease_occupancy(null, 7, ['bedrooms' => null], false, false, null, '');
check('unit without bedrooms: lease saves with no value', null, $r['max_occupants']);

// --- guarantor rule -----------------------------------------------------------
check('company over 100k: required', true, contract_v2_guarantor_required('company', 100000.01));
check('company exactly 100k: optional', false, contract_v2_guarantor_required('company', 100000));
check('individual over 100k: optional', false, contract_v2_guarantor_required('individual', 250000));

// --- full render of the v2 template -------------------------------------------
$tplHtml = file_get_contents(__DIR__ . '/../modules/realestate/contract_templates/tenancy_contract_v2.html');
$template = ['id' => 0, 'template_code' => 'tenancy_contract_v2'];
$baseLease = [
    'id' => 0, 'company_id' => 2, 'tenant_id' => 0, 'tenant_type' => 'individual', 'is_multi_unit' => 0,
    'company_name' => 'AIN AL REEM PROPERTIES L.L.C.', 'first_name' => 'Test', 'last_name' => 'Tenant',
    'tenant_company_name' => '', 'phone' => '+971 50 000 0000', 'email' => 'tenant@example.com', 'id_number' => '784-0000-0000000-0',
    'lease_number' => 'AYLARESI-999-0001', 'building_name' => 'AYLA RESIDENCE', 'unit_number' => '999', 'premises_number' => '123456789',
    'start_date' => '2026-12-15', 'end_date' => '2027-12-14', 'annual_rent' => 80000, 'security_deposit' => 4000,
    'number_of_installments' => 4, 'payment_method' => 'cheque', 'notes' => '', 'max_occupants' => 4,
    '__payment_schedule' => [
        ['due_date' => '2026-12-15', 'amount' => 20000, 'cheque_no' => '000101', 'bank' => 'ENBD'],
        ['due_date' => '2027-03-15', 'amount' => 20000, 'cheque_no' => '000102', 'bank' => 'ENBD'],
        ['due_date' => '2027-06-15', 'amount' => 20000, 'cheque_no' => '000103', 'bank' => 'ENBD'],
        ['due_date' => '2027-09-15', 'amount' => 20000, 'cheque_no' => '000104', 'bank' => 'ENBD'],
    ],
    '__move_in' => null,
];
$gen = new ContractPDFGenerator($conn, 2);
$rp = (new ReflectionClass($gen))->getMethod('replacePlaceholders');
$rp->setAccessible(true);
$render = fn(array $lease) => $rp->invoke($gen, $tplHtml, $lease, $template);

$clauseNumbers = function (string $html): array {
    preg_match_all('/<td class="num-en">(\d+)\.<\/td>/', $html, $m);
    return array_map('intval', $m[1]);
};

$out = $render($baseLease);
check('no guarantor: 33 clauses numbered 1..33', range(1, 33), $clauseNumbers($out));
check('no guarantor: no placeholders left', 0, preg_match('/\{\{/', $out));
check('no guarantor: guarantor clause absent', false, strpos($out, 'principal obligor') !== false);
check('pending markers = 14 changed/new clauses (no guarantor)', 14, substr_count($out, 'TRANSLATION PENDING – Clause'));
check('pending markers on Annexes A-D', 4, substr_count($out, 'TRANSLATION PENDING – Annex'));
check('threshold printed in figures and words', true, strpos($out, 'AED 500 (Five Hundred Dirhams)') !== false);
check('Ejari days printed', true, strpos($out, 'within seven (7) working days') !== false);
check('17(b) figure and words', true, strpos($out, 'not exceeding 4 (four) persons of any age, as a single family') !== false);
check('Annex B occupant rows = 4', 4, preg_match_all('/<tr><td class="c">\\d<\/td><td class="annex-blank">&nbsp;<\/td><td class="annex-blank">&nbsp;<\/td><\/tr>/', $out));
check('Annex B max note', true, strpos($out, 'Maximum occupants under Clause 17(b): 4') !== false);
check('page 1 payment summary', true, strpos($out, '4 instalments as per Annex A') !== false);
check('Annexes A, B, C present', 3, preg_match_all('/Annex [ABC] - /', $out));
check('Annex A lists 4 cheques', true, strpos($out, '000104') !== false && strpos($out, '80,000.00') !== false);
check('renewal fee clause included by default', true, strpos($out, 'AED 1,500 or 2%') !== false);
check('old bounced cheque penalty gone', false, strpos($out, 'contractual penalty of AED 500') !== false);
check('cheque handling fee printed', true, strpos($out, 'administrative handling fee of AED 500 (Five Hundred Dirhams) per returned cheque') !== false);
check('late payment penalty gone', false, strpos($out, 'late payment penalty') !== false);
check('deposit clause allows clause 12(b) deductions', true, strpos($out, 'any unpaid amounts invoiced under Clause 12(b)') !== false);
check('RDC jurisdiction', true, strpos($out, 'Rental Disputes Centre of the Emirate of Dubai') !== false);

check('Annex D after Annex C', true, strpos($out, 'Annex D — Schedule of Rates') > strpos($out, 'Annex C - Move-Out'));
check('Annex D lists 22 items, all blank as —', 22, substr_count($out, '<td class="r">—</td>'));
check('deposit (a) uses Annex D', true, strpos($out, 'applicable rate from the Schedule of Rates (Annex D)') !== false);
check('deposit (b) objection does not suspend repairs', true, strpos($out, 'does not suspend the Landlord') !== false);
check('deposit (d) keys at Annex D rates', true, strpos($out, 'unreturned keys or access cards at the rates in Annex D,') !== false);
$v2data = contract_v2_values(null, $baseLease);
check('warning lists items with no rate', true, (bool)array_filter($v2data['warnings'], fn($w) => strpos($w, '22 item(s)') !== false));
$hard = $baseLease;
$hard['__rates_required'] = true;
check('hard-fail switch: blank rate stops the PDF', true, throws(fn() => $render($hard), 'Annex D has no rate for'));
$rated = $baseLease;
$rated['__schedule_of_rates'] = array_map(function ($r) { $r['rate_aed'] = 100; return $r; }, contract_v2_schedule_of_rates());
check('all rates set: none blank', 0, substr_count($render($rated), '<td class="r">—</td>'));
$snap = contract_v2_schedule_of_rates();
$snap[0]['rate_aed'] = 250;
$kept = contract_v2_rates_for_lease(['contract_rates_snapshot' => json_encode($snap)]);
check('saved lease copy keeps its rate', 250, $kept[0]['rate_aed']);

$g = $baseLease;
$g['guarantor_name'] = 'Guarantor Person';
$g['guarantor_eid'] = '784-1111-1111111-1';
$out = $render($g);
check('with guarantor: 34 clauses numbered 1..34', range(1, 34), $clauseNumbers($out));
check('with guarantor: 15 pending markers', 15, substr_count($out, 'TRANSLATION PENDING – Clause'));
check('with guarantor: clause and signature line', true,
    strpos($out, 'Guarantor Person (Emirates ID 784-1111-1111111-1) irrevocably') !== false && strpos($out, "Guarantor's Signature") !== false);

$noFee = $baseLease;
$noFee['__include_renewal_fee'] = false;
check('renewal fee off: 32 clauses, no gap', range(1, 32), $clauseNumbers($render($noFee)));

$noBeds = $baseLease;
unset($noBeds['max_occupants']);
$noBeds['unit_bedrooms'] = null;
check('contract refused when unit has no bedrooms', true, throws(fn() => $render($noBeds), 'no bedrooms'));
$fromUnit = $baseLease;
unset($fromUnit['max_occupants']);
$fromUnit['unit_bedrooms'] = 1;
$fromUnit['unit_bedroom_area_sqm'] = 14;
$o = $render($fromUnit);
check('1BR 14 sqm in contract: 2 (two), 2 rows', [true, true], [strpos($o, 'not exceeding 2 (two) persons') !== false, strpos($o, 'Maximum occupants under Clause 17(b): 2') !== false]);

$co = $baseLease;
$co['tenant_type'] = 'company';
$co['annual_rent'] = 150000;
check('company over 100k without guarantor: refused', true, throws(fn() => $render($co), 'needs a guarantor'));

$broken = ['id' => 0, 'template_code' => 'tenancy_contract_v2'];
check('unknown placeholder in v2 template: refused', true,
    throws(fn() => $rp->invoke($gen, '<p>{{CLAUSE_NO}} {{NOT_A_FIELD}}</p>', $baseLease, $broken), 'NOT_A_FIELD'));

$ppHtml = file_get_contents(__DIR__ . '/../modules/realestate/contract_templates/tenancy_contract_pp_v2.html');
$ppLease = $baseLease;
$ppLease['chiller_fees'] = 3000;
$ppOut = $rp->invoke($gen, $ppHtml, $ppLease, ['id' => 0, 'template_code' => 'tenancy_contract_pp_v2']);
check('Park Place v2: rent + chiller line', true, strpos($ppOut, 'AED 80,000.00 + Chiller Fees AED 3,000.00 = AED 83,000.00') !== false);
check('Park Place v2: Landlord may increase chiller at renewal', true, strpos($ppOut, 'The Landlord may increase the chiller charges at renewal by giving the Tenant written notice at least ninety (90) days before the expiry date.') !== false);
check('Park Place v2: chiller payable to Landlord (cl. 18)', true, strpos($ppOut, 'Chiller charges are payable to the Landlord as stated on page 1 and in Annex A.') !== false);
check('Park Place v2: no chiller clearance certificate (cl. 20b)', [false, true], [strpos($ppOut, 'certificates for DEWA, district cooling') !== false, strpos($ppOut, 'has paid all chiller charges due to the Landlord') !== false]);
check('Park Place v2: 33 clauses, nothing unfilled', [range(1, 33), 0], [$clauseNumbers($ppOut), preg_match('/\{\{/', $ppOut)]);

// --- Commercial v2.1 (Ayla: shop / office / food court) -------------------------
$comHtml = file_get_contents(__DIR__ . '/../modules/realestate/contract_templates/tenancy_contract_com_v2.html');
$comTpl = ['id' => 0, 'template_code' => 'tenancy_contract_com_v2'];
$comLease = $baseLease;
unset($comLease['max_occupants'], $comLease['occupancy_type']);
$comLease['unit_bedrooms'] = null; // shops have no bedrooms: must still print
$comLease += ['commercial_use' => 'shop', 'permitted_activity' => 'Grocery', 'trade_licence_no' => '1234567',
    'fit_out_days' => 0, 'vat_applicable_on_rent' => 1, 'rent_vat_amount' => 4000, 'lease_vat_rate' => 5,
    'guarantor_waived_reason' => 'Test: no guarantor', 'tenant_trn' => '100200300400003', 'unit_size_sqft' => 595.14,
    'building_address' => 'JVC District 15', '__landlord_trn' => '100999888700003'];
$comRender = fn(array $l) => $rp->invoke($gen, $comHtml, $l, $comTpl);
$clauseNoOf = function (string $html, string $text): int {
    $i = strpos($html, $text);
    if ($i === false) return 0;
    preg_match_all('/<td class="num-en">(\d+)\.<\/td>/', substr($html, 0, $i), $m);
    return (int)end($m[1]);
};
$withG = fn(array $l) => array_merge($l, ['guarantor_name' => 'Guarantor Person', 'guarantor_eid' => '784-1111-1111111-1',
    'guarantor_phone' => '+971 50 000 0000', 'guarantor_email' => 'g@example.com', 'guarantor_waived_reason' => '']);

$cs = $comRender($comLease); // shop, no guarantor (waived)
check('v2.1 shop, no guarantor: 38 clauses, nothing unfilled', [range(1, 38), 0], [$clauseNumbers($cs), preg_match('/\{\{/', $cs)]);
check('v2.1: every clause has pending Arabic', 38, substr_count($cs, 'TRANSLATION PENDING – Clause'));
check('v2.1 cross-refs (shop, no G): 31 reinstatement, 30 fit-out, 20 deposit, 27 holdover', true,
    strpos($cs, 'subject to Clause 31.') !== false && strpos($cs, "prior written consent under Clause 30. Signs are governed by Clause 8.") !== false
    && strpos($cs, 'in accordance with Clause 20 and Annex D') !== false && strpos($cs, 'Clause 27 applies until reinstatement') !== false
    && strpos($cs, 'in breach of Clause 21(c)') !== false && strpos($cs, 'invoiced under Clause 12(b)') !== false);
check('v2.1 page 1: TRNs, area, community, words, service charge, parking, fit-out deposit None', true,
    strpos($cs, 'TRN 100200300400003') !== false && strpos($cs, 'TRN 100999888700003') !== false && strpos($cs, '595.14 sq ft') !== false
    && strpos($cs, 'JVC District 15, Dubai') !== false && strpos($cs, '(Eighty Thousand Dirhams)') !== false
    && strpos($cs, 'None — included in the rent') !== false && strpos($cs, 'None, unless agreed by the Landlord in writing') !== false);
check('v2.1 shop-only text: 21(c) and customer parking printed', [true, true],
    [strpos($cs, 'open for business within thirty (30) days') !== false, strpos($cs, 'Customer parking is subject') !== false]);
check('v2.1 Annex A has VAT columns; Annex B FCUs + fit-out line; Annex C reinstatement under 31', true,
    strpos($cs, '<th>VAT (AED)</th>') !== false && strpos($cs, 'Air-conditioning (base-build FCUs)') !== false
    && strpos($cs, 'Fit-out drawings approved') !== false && strpos($cs, 'Reinstatement under Clause 31') !== false
    && strpos($cs, 'DEWA (water)') !== false);
check('v2.1 Annex D: commercial list, 12 items for a shop (no grease trap)', [12, false],
    [substr_count($cs, '<td class="r">—</td>'), strpos($cs, 'Grease trap and exhaust cleaning') !== false]);
check('v2.1 signature page: 2 columns without guarantor, checklist printed', [false, true],
    [strpos($cs, '<th>Guarantor</th>') !== false, strpos($cs, 'Attachments received at signing') !== false]);

$off = $comLease; $off['commercial_use'] = 'office';
$co = $comRender($off);
check('v2.1 office, no guarantor: 38 clauses; office text; no 21(c), no customer parking', [range(1, 38), true, false, false],
    [$clauseNumbers($co), strpos($co, 'display its name only in the place and style') !== false,
     strpos($co, 'open for business within thirty (30) days') !== false, strpos($co, 'Customer parking is subject') !== false]);
check('v2.1 office: 16 still refers to 21(c)', true, strpos($co, 'in breach of Clause 21(c)') !== false);

$fc = $withG($comLease); $fc['commercial_use'] = 'food_court'; $fc['fit_out_days'] = 30; $fc['fit_out_deposit'] = 10000;
$cf = $comRender($fc);
check('v2.1 food court + guarantor: 40 clauses, refs move (reinstatement still 31; POA 37)', [range(1, 40), 31, 37, 36],
    [$clauseNumbers($cf), $clauseNoOf($cf, 'Reinstatement and abandoned goods.'), $clauseNoOf($cf, 'Powers of attorney.'), $clauseNoOf($cf, 'Guarantee.')]);
check('v2.1 food court: fit-out deposit refers to 30, 30 days, kitchen items in Annex B, grease trap rate', true,
    strpos($cf, 'AED 10,000.00 (refundable under Clause 30)') !== false && strpos($cf, '30 days from the start date') !== false
    && strpos($cf, 'Grease trap</td>') !== false && strpos($cf, 'Grease trap and exhaust cleaning') !== false);
check('v2.1 guarantor: page 1 row, clause 36, 3-column signature', true,
    strpos($cf, 'Mobile +971 50 000 0000') !== false && strpos($cf, '<th>Guarantor</th>') !== false && strpos($cf, 'jointly and severally with the Tenant') !== false);
$nf = $comLease; $nf['__include_renewal_fee'] = false;
check('v2.1 renewal fee off: 37 clauses, refs still resolve', [range(1, 37), true],
    [$clauseNumbers($comRender($nf)), strpos($comRender($nf), 'subject to Clause 30.') !== false]);

$noG = $comLease; $noG['guarantor_waived_reason'] = '';
check('v2.1: refused without guarantor and without Admin reason', true, throws(fn() => $comRender($noG), 'needs a guarantor'));
$noUse = $comLease; $noUse['commercial_use'] = '';
check('commercial: refused without Commercial Use', true, throws(fn() => $comRender($noUse), 'Commercial Use'));
$noVat = $comLease; $noVat['vat_applicable_on_rent'] = 0; $noVat['rent_vat_amount'] = 0;
check('commercial: refused without VAT on rent', true, throws(fn() => $comRender($noVat), 'VAT'));
check('reference to a clause not printed: refused', true,
    throws(fn() => contract_v2_number_clauses('{{CLAUSE_NO:a}} see {{REF:missing}}'), 'not printed'));
check('reference before the clause resolves', '1 see 2 2', contract_v2_number_clauses('{{CLAUSE_NO:a}} see {{REF:b}} {{CLAUSE_NO:b}}'));
// Every {{REF:x}} in the template points at a {{CLAUSE_NO:x}} key.
preg_match_all('/\{\{REF:([a-z0-9_]+)\}\}/', $comHtml . implode('', array_map(fn($v) => (string)$v, contract_v2_commercial_v21_values(null, $fc))), $refs);
preg_match_all('/\{\{CLAUSE_NO:([a-z0-9_]+)\}\}/', $comHtml, $keysFound);
check('every reference in v2.1 has a clause key', [], array_values(array_diff(array_unique($refs[1]), $keysFound[1])));

$comLease['guarantor_waived_reason'] = 'Test: no guarantor';
// --- Park Place Commercial -------------------------------------------------------
$tplFile = fn(string $code) => file_get_contents(__DIR__ . '/../modules/realestate/contract_templates/' . $code . '.html');
$renderAs = fn(string $code, array $l) => $rp->invoke($gen, $tplFile($code), $l, ['id' => 0, 'template_code' => $code]);
$ppc = $renderAs('tenancy_contract_pp_com_v2', $comLease);
check('PP commercial, no chiller on lease: 38 clauses, prints like Ayla', [range(1, 38), 0, false, true],
    [$clauseNumbers($ppc), preg_match('/\{\{/', $ppc), strpos($ppc, 'Chiller Fees (plus VAT)') !== false, strpos($ppc, 'certificates for DEWA, district cooling / chiller') !== false]);
$ppcCh = $comLease; $ppcCh['chiller_fees'] = 6000;
$ppc = $renderAs('tenancy_contract_pp_com_v2', $ppcCh);
check('PP commercial with chiller: page 1 line, cl.18 payable to Landlord, cl.20(b) no clearance', [range(1, 38), true, true, true, false],
    [$clauseNumbers($ppc), strpos($ppc, 'AED 6,000.00') !== false, strpos($ppc, 'Chiller charges are payable to the Landlord') !== false,
     strpos($ppc, 'has paid all chiller charges due to the Landlord') !== false, strpos($ppc, 'sewage, district cooling / chiller charges') !== false]);

// --- Camp Commercial ----------------------------------------------------------------
foreach (['gc' => false, 'lc' => true] as $camp => $isLadies) {
    $o = $renderAs('tenancy_contract_' . $camp . '_com_v2', $comLease);
    check($camp . ' commercial shop: 39 clauses, camp facilities + camp rules, ladies line ' . ($isLadies ? 'on' : 'off'),
        [range(1, 39), 0, true, true, $isLadies],
        [$clauseNumbers($o), preg_match('/\{\{/', $o), strpos($o, "camp's rooms, dining hall, kitchen") !== false,
         strpos($o, 'Camp rules and security.') !== false, strpos($o, 'shall not enter the accommodation areas of the camp') !== false]);
    $f = $comLease; $f['commercial_use'] = 'food_court';
    check($camp . ' commercial food court: 40 clauses', range(1, 40), $clauseNumbers($renderAs('tenancy_contract_' . $camp . '_com_v2', $f)));
}

// --- Labour accommodation -----------------------------------------------------------
$labLease = $baseLease;
unset($labLease['max_occupants'], $labLease['occupancy_type']);
$labLease['unit_bedrooms'] = null;
$labLease = array_merge($labLease, ['tenant_type' => 'company', 'annual_rent' => 90000, 'persons_per_room' => 6,
    'trade_licence_no' => 'CN-555', 'is_multi_unit' => 1, 'premises_number' => 'SHOULD-NOT-PRINT', 'unit_number' => 'SHOULD-NOT-PRINT',
    '__rooms' => array_map(fn($i) => ['room' => (string)(100 + $i), 'premises' => 'P' . $i], range(1, 10))]);
foreach (['gc' => false, 'lc' => true] as $camp => $isLadies) {
    $o = $renderAs('tenancy_contract_' . $camp . '_lab_v2', $labLease);
    check($camp . ' labour: 40 clauses, nothing unfilled', [range(1, 40), 0], [$clauseNumbers($o), preg_match('/\{\{/', $o)]);
    check($camp . ' labour: rent 12, deposit 20, notices 28, workers 21', [12, 20, 28, 21],
        [$clauseNoOf($o, 'Rent payment and returned cheques'), $clauseNoOf($o, 'Security deposit.'), $clauseNoOf($o, "The Tenant's address for contractual notices"), $clauseNoOf($o, 'Workers and occupancy.')]);
    check($camp . ' labour page 1: 10 rooms, 6 per room, 60 total, licence; long unit list not printed', [true, true, true, false],
        [strpos($o, '10 room(s), listed in Annex E') !== false, strpos($o, 'no more than 6 (six) persons') !== false,
         strpos($o, '>60<') !== false && strpos($o, '>CN-555<') !== false, strpos($o, 'SHOULD-NOT-PRINT') !== false]);
    check($camp . ' labour Annex E lists rooms with DEWA no.', [true, true], [strpos($o, 'Annex E - List of Rooms') !== false, strpos($o, '<td>110</td><td>P10</td>') !== false]);
    check($camp . ' labour: no occupants list, no pets, no VAT clause text right', [false, false, true],
        [strpos($o, '5. Occupants') !== false, strpos($o, 'keep pets') !== false, strpos($o, 'Rent is not subject to VAT.') !== false]);
    check($camp . ' labour: gender line', [$isLadies, !$isLadies],
        [strpos($o, 'Only female workers may stay') !== false, strpos($o, 'Visitors are allowed only in the areas the Landlord sets') !== false]);
    $g = $labLease; $g['guarantor_name'] = 'Guarantor Person'; $g['guarantor_eid'] = '784-1111-1111111-1';
    check($camp . ' labour + guarantor: 41 clauses', range(1, 41), $clauseNumbers($renderAs('tenancy_contract_' . $camp . '_lab_v2', $g)));
    $np = $labLease; $np['persons_per_room'] = 0;
    check($camp . ' labour: refused without persons per room', true, throws(fn() => $renderAs('tenancy_contract_' . $camp . '_lab_v2', $np), 'Persons per Room'));
}

$v1 = ['id' => 3, 'template_code' => null];
check('old template: unknown placeholders left alone (no check)', '<p>{{NOT_A_FIELD}}</p>',
    $rp->invoke($gen, '<p>{{NOT_A_FIELD}}</p>', $baseLease, $v1));

echo $failures ? "\n{$failures} FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
