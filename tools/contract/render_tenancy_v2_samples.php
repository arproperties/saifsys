<?php
/**
 * Renders the two Tenancy Contract v2 sample PDFs from test data (no database rows used
 * except the company name), with the same mPDF settings, header and footer as real contracts.
 *
 *     php tools/contract/render_tenancy_v2_samples.php [output_dir]
 *
 * Default output: docs/contract/samples/
 */
$root = dirname(__DIR__, 2);
require_once $root . '/includes/db_connect.php';
require_once $root . '/includes/company_helper.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/modules/realestate/includes/contract_pdf_generator.php';
require_once $root . '/modules/realestate/includes/contract_v2_helper.php';

$outDir = rtrim($argv[1] ?? ($root . '/docs/contract/samples'), '/');
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$html = file_get_contents($root . '/modules/realestate/contract_templates/tenancy_contract_v2.html');
$template = ['id' => 0, 'template_code' => CONTRACT_V2_TEMPLATE_CODE];

$lease = [
    'id' => 0, 'company_id' => 2, 'tenant_id' => 0, 'tenant_type' => 'individual', 'is_multi_unit' => 0,
    'company_name' => 'AIN AL REEM PROPERTIES L.L.C.',
    'first_name' => 'SAMPLE', 'last_name' => 'TENANT', 'tenant_company_name' => '',
    'phone' => '+971 50 123 4567', 'email' => 'sample.tenant@example.com', 'id_number' => '784-1990-1234567-1',
    'lease_number' => 'AYLARESI-SAMPLE-0001', 'building_name' => 'AYLA RESIDENCE', 'unit_number' => '305',
    'premises_number' => '682-11223-4', 'start_date' => '2026-12-15', 'end_date' => '2027-12-14',
    'annual_rent' => 85000, 'security_deposit' => 4250, 'number_of_installments' => 4, 'payment_method' => 'cheque',
    'notes' => '', 'max_occupants' => 4, 'occupancy_type' => 'single_family',
    '__payment_schedule' => [
        ['due_date' => '2026-12-15', 'amount' => 21250, 'cheque_no' => '000201', 'bank' => 'Emirates NBD'],
        ['due_date' => '2027-03-15', 'amount' => 21250, 'cheque_no' => '000202', 'bank' => 'Emirates NBD'],
        ['due_date' => '2027-06-15', 'amount' => 21250, 'cheque_no' => '000203', 'bank' => 'Emirates NBD'],
        ['due_date' => '2027-09-15', 'amount' => 21250, 'cheque_no' => '000204', 'bank' => 'Emirates NBD'],
    ],
    '__move_in' => null,
];

$withGuarantor = $lease;
$withGuarantor['tenant_type'] = 'company';
$withGuarantor['tenant_company_name'] = 'SAMPLE TRADING L.L.C.';
$withGuarantor['annual_rent'] = 160000;
$withGuarantor['security_deposit'] = 8000;
$withGuarantor['lease_number'] = 'AYLARESI-SAMPLE-0002';
$withGuarantor['occupancy_type'] = 'company_staff';
$withGuarantor['guarantor_name'] = 'SAMPLE GUARANTOR';
$withGuarantor['guarantor_eid'] = '784-1985-7654321-2';
$withGuarantor['__payment_schedule'] = array_map(function ($r) {
    $r['amount'] = 40000;
    return $r;
}, $lease['__payment_schedule']);

$gen = new ContractPDFGenerator($conn, 2);
$rc = new ReflectionClass($gen);
foreach (['replacePlaceholders', 'generateHeaderHTML', 'generateFooterHTML'] as $m) {
    $rc->getMethod($m)->setAccessible(true);
}

$samples = [
    'tenancy_contract_v2_sample_4_cheques_no_guarantor.pdf' => $lease,
    'tenancy_contract_v2_sample_with_guarantor.pdf' => $withGuarantor,
];
// Max occupants worked out from the unit (no value saved on the lease yet).
foreach ([
    'occupancy_studio' => [0, null],
    'occupancy_1br_14sqm' => [1, 14],
    'occupancy_2br_30sqm' => [2, 30],
    'occupancy_2br_no_area' => [2, null],
] as $name => [$beds, $area]) {
    $l = $lease;
    unset($l['max_occupants']);
    $l['unit_bedrooms'] = $beds;
    $l['unit_bedroom_area_sqm'] = $area;
    $samples['tenancy_contract_v2_sample_' . $name . '.pdf'] = $l;
}
$ppLease = $lease;
$ppLease['lease_number'] = 'PARKPLAC-SAMPLE-0001';
$ppLease['building_name'] = 'PARK PLACE';
$ppLease['chiller_fees'] = 3500;
$samples['tenancy_contract_pp_v2_sample_no_guarantor.pdf'] = $ppLease + ['__template' => 'pp'];
// Ayla Commercial v2: one template, the use on the lease picks the shop / office / food court wording.
$comLease = $lease;
unset($comLease['max_occupants'], $comLease['occupancy_type']);
$comLease = array_merge($comLease, [
    'tenant_type' => 'company', 'tenant_company_name' => 'SAMPLE TRADING L.L.C.', 'first_name' => '', 'last_name' => '',
    'unit_number' => 'SHOP 02', 'lease_number' => 'AYLARESI-SAMPLE-C001', 'annual_rent' => 90000, 'security_deposit' => 9000,
    'vat_applicable_on_rent' => 1, 'rent_vat_amount' => 4500, 'lease_vat_rate' => 5,
    'commercial_use' => 'shop', 'permitted_activity' => 'Grocery', 'trade_licence_no' => '1234567', 'fit_out_days' => 0,
    '__template' => 'com',
]);
$comLease['__payment_schedule'] = array_map(function ($r) {
    $r['amount'] = 23625;
    return $r;
}, $lease['__payment_schedule']);
// Ayla Commercial v2.1: the three samples from the v2.1 task (VAT per cheque so Annex A reconciles with page 1).
$comLease['__payment_schedule'] = array_map(function ($r) {
    $r['amount'] = 23625;
    $r['vat'] = 1125;
    return $r;
}, $lease['__payment_schedule']);
$comLease = array_merge($comLease, [
    'tenant_trn' => '100200300400003', 'tenant_signatory_name' => 'SAMPLE SIGNATORY', 'tenant_signatory_eid' => '784-1980-1111111-1',
    'tenant_bank_account_name' => 'SAMPLE TRADING L.L.C.', 'unit_size_sqft' => 595.14, 'building_address' => 'JVC District 15 - Jumeirah Village Circle',
]);
$g = ['guarantor_name' => 'SAMPLE GUARANTOR', 'guarantor_eid' => '784-1985-7654321-2', 'guarantor_phone' => '+971 50 765 4321',
    'guarantor_email' => 'guarantor@example.com'];
$samples['tenancy_contract_com_v2.1_sample_office_no_guarantor.pdf'] = array_merge($comLease, [
    'unit_number' => 'OFFICE 01', 'lease_number' => 'AYLARESI-SAMPLE-C002', 'commercial_use' => 'office',
    'permitted_activity' => 'Real estate brokerage', 'guarantor_waived_reason' => 'Sample: no guarantor (Admin)',
]);
$samples['tenancy_contract_com_v2.1_sample_shop_with_guarantor.pdf'] = array_merge($comLease, $g);
$samples['tenancy_contract_com_v2.1_sample_food_court_with_guarantor_30_day_fitout.pdf'] = array_merge($comLease, $g, [
    'unit_number' => 'FOOD COURT 01', 'lease_number' => 'AYLARESI-SAMPLE-C003', 'commercial_use' => 'food_court',
    'permitted_activity' => 'Restaurant', 'annual_rent' => 160000, 'rent_vat_amount' => 8000, 'security_deposit' => 16000,
    'fit_out_days' => 30, 'fit_out_deposit' => 10000,
    '__payment_schedule' => array_map(function ($r) {
        $r['amount'] = 42000;
        $r['vat'] = 2000;
        return $r;
    }, $lease['__payment_schedule']),
]);
// Park Place Commercial (sample shows the chiller lines), camp shops, labour accommodation.
$samples['tenancy_contract_pp_com_v2_sample_shop_with_chiller.pdf'] = array_merge($comLease, $g, [
    'building_name' => 'PARK PLACE', 'lease_number' => 'PARKPLAC-SAMPLE-C001', 'unit_number' => 'SHOP 01', 'chiller_fees' => 6000,
    '__template' => 'tenancy_contract_pp_com_v2',
]);
$samples['tenancy_contract_gc_com_v2_sample_shop.pdf'] = array_merge($comLease, [
    'building_name' => 'Gents Camp (G+5 Building)', 'lease_number' => 'GENTSCAM-SAMPLE-C001', 'unit_number' => 'SHOP 01',
    'permitted_activity' => 'Cafeteria', '__template' => 'tenancy_contract_gc_com_v2',
]);
$samples['tenancy_contract_lc_com_v2_sample_shop.pdf'] = array_merge($comLease, [
    'building_name' => 'Ladies Camp (G+4 Building)', 'lease_number' => 'LADIESCA-SAMPLE-C001', 'unit_number' => 'SHOP 01',
    'permitted_activity' => 'Ladies salon', '__template' => 'tenancy_contract_lc_com_v2',
]);
$labLease = $comLease;
unset($labLease['commercial_use'], $labLease['permitted_activity'], $labLease['fit_out_days'], $labLease['vat_applicable_on_rent'], $labLease['rent_vat_amount']);
$labLease = array_merge($labLease, [
    'tenant_company_name' => 'SAMPLE CONTRACTING L.L.C.', 'annual_rent' => 96000, 'security_deposit' => 0, 'is_multi_unit' => 1,
    'persons_per_room' => 6, 'trade_licence_no' => 'CN-1234567',
    '__payment_schedule' => array_map(function ($r) {
        $r['amount'] = 24000;
        return $r;
    }, $lease['__payment_schedule']),
]);
$samples['tenancy_contract_gc_lab_v2_sample_20_rooms.pdf'] = array_merge($labLease, [
    'building_name' => 'Gents Camp (G+5 Building)', 'lease_number' => 'GENTSCAM-SAMPLE-L001',
    '__rooms' => array_map(fn($i) => ['room' => (string)(100 + $i), 'premises' => '6821' . (2000 + $i)], range(1, 20)),
    '__template' => 'tenancy_contract_gc_lab_v2',
]);
$samples['tenancy_contract_lc_lab_v2_sample_8_rooms.pdf'] = array_merge($labLease, [
    'building_name' => 'Ladies Camp (G+4 Building)', 'lease_number' => 'LADIESCA-SAMPLE-L001', 'annual_rent' => 38400,
    'persons_per_room' => 4,
    '__rooms' => array_map(fn($i) => ['room' => (string)(300 + $i), 'premises' => '6822' . (3000 + $i)], range(1, 8)),
    '__payment_schedule' => array_map(function ($r) {
        $r['amount'] = 9600;
        return $r;
    }, $lease['__payment_schedule']),
    '__template' => 'tenancy_contract_lc_lab_v2',
]);
foreach ($samples as $file => $l) {
    $which = $l['__template'] ?? '';
    $code = ['pp' => 'tenancy_contract_pp_v2', 'com' => 'tenancy_contract_com_v2', '' => CONTRACT_V2_TEMPLATE_CODE][$which] ?? $which;
    $body = $rc->getMethod('replacePlaceholders')->invoke($gen,
        file_get_contents($root . '/modules/realestate/contract_templates/' . $code . '.html'), $l,
        ['id' => 0, 'template_code' => $code]);
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => 'A4',
        'margin_top' => 35, 'margin_bottom' => 30, 'margin_left' => 15, 'margin_right' => 15,
        'tempDir' => sys_get_temp_dir(), 'default_font' => 'dejavusans',
        'autoScriptToLang' => true, 'autoLangToFont' => true,
    ]);
    $mpdf->SetDirectionality('auto');
    $mpdf->SetHTMLHeader($rc->getMethod('generateHeaderHTML')->invoke($gen, $l));
    $mpdf->SetHTMLFooter($rc->getMethod('generateFooterHTML')->invoke($gen));
    $mpdf->WriteHTML($body);
    $mpdf->Output($outDir . '/' . $file, \Mpdf\Output\Destination::FILE);
    echo $outDir . '/' . $file . ' (' . count($mpdf->pages) . " pages)\n";
}
