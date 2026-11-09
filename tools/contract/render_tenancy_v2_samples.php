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
foreach ($samples as $file => $l) {
    $body = $rc->getMethod('replacePlaceholders')->invoke($gen, $html, $l, $template);
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
