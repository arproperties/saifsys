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
    'number_of_installments' => 4, 'payment_method' => 'cheque', 'notes' => '',
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
check('pending markers = 14 changed/new clauses (no guarantor)', 14, substr_count($out, 'TRANSLATION PENDING'));
check('threshold printed in figures and words', true, strpos($out, 'AED 500 (Five Hundred Dirhams)') !== false);
check('Ejari days printed', true, strpos($out, 'within seven (7) working days') !== false);
check('occupancy default', true, strpos($out, 'not exceeding 4 persons, as a single family') !== false);
check('page 1 payment summary', true, strpos($out, '4 instalments as per Annex A') !== false);
check('Annexes A, B, C present', 3, preg_match_all('/Annex [ABC] - /', $out));
check('Annex A lists 4 cheques', true, strpos($out, '000104') !== false && strpos($out, '80,000.00') !== false);
check('renewal fee clause included by default', true, strpos($out, 'AED 1,500 or 2%') !== false);
check('old bounced cheque penalty gone', false, strpos($out, 'contractual penalty of AED 500') !== false);
check('RDC jurisdiction', true, strpos($out, 'Rental Disputes Centre of the Emirate of Dubai') !== false);

$g = $baseLease;
$g['guarantor_name'] = 'Guarantor Person';
$g['guarantor_eid'] = '784-1111-1111111-1';
$out = $render($g);
check('with guarantor: 34 clauses numbered 1..34', range(1, 34), $clauseNumbers($out));
check('with guarantor: 15 pending markers', 15, substr_count($out, 'TRANSLATION PENDING'));
check('with guarantor: clause and signature line', true,
    strpos($out, 'Guarantor Person (Emirates ID 784-1111-1111111-1) irrevocably') !== false && strpos($out, "Guarantor's Signature") !== false);

$noFee = $baseLease;
$noFee['__include_renewal_fee'] = false;
check('renewal fee off: 32 clauses, no gap', range(1, 32), $clauseNumbers($render($noFee)));

$co = $baseLease;
$co['tenant_type'] = 'company';
$co['annual_rent'] = 150000;
check('company over 100k without guarantor: refused', true, throws(fn() => $render($co), 'needs a guarantor'));

$broken = ['id' => 0, 'template_code' => 'tenancy_contract_v2'];
check('unknown placeholder in v2 template: refused', true,
    throws(fn() => $rp->invoke($gen, '<p>{{CLAUSE_NO}} {{NOT_A_FIELD}}</p>', $baseLease, $broken), 'NOT_A_FIELD'));

$v1 = ['id' => 3, 'template_code' => null];
check('old template: unknown placeholders left alone (no check)', '<p>{{NOT_A_FIELD}}</p>',
    $rp->invoke($gen, '<p>{{NOT_A_FIELD}}</p>', $baseLease, $v1));

echo $failures ? "\n{$failures} FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
