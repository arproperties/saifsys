<?php
$root = '/Users/ainalreem/Sites/saifsys';
require $root . '/includes/db_connect.php';
require $root . '/includes/company_helper.php';
require $root . '/vendor/autoload.php';
require $root . '/modules/realestate/includes/contract_pdf_generator.php';
require $root . '/modules/realestate/includes/contract_v2_helper.php';
$html = file_get_contents($argv[1]);
$lease = [
 'id'=>0,'company_id'=>2,'tenant_id'=>0,'tenant_type'=>'company','is_multi_unit'=>0,'company_name'=>'AIN AL REEM PROPERTIES L.L.C.',
 'first_name'=>'','last_name'=>'','tenant_company_name'=>'SAMPLE TRADING L.L.C.','phone'=>'+971 50 123 4567','email'=>'sample.tenant@example.com',
 'id_number'=>'784-1990-1234567-1','lease_number'=>'AYLARESI-SAMPLE-0003','building_name'=>'AYLA RESIDENCE','unit_number'=>'305',
 'premises_number'=>'682-11223-4','start_date'=>'2026-12-15','end_date'=>'2027-12-14','annual_rent'=>160000,'security_deposit'=>8000,
 'number_of_installments'=>4,'payment_method'=>'cheque','notes'=>'','max_occupants'=>4,'occupancy_type'=>'single_family',
 'guarantor_name'=>'SAMPLE GUARANTOR','guarantor_eid'=>'784-1985-7654321-2',
 '__payment_schedule'=>[['due_date'=>'2026-12-15','amount'=>40000,'cheque_no'=>'000301','bank'=>'Emirates NBD'],['due_date'=>'2027-03-15','amount'=>40000,'cheque_no'=>'000302','bank'=>'Emirates NBD'],['due_date'=>'2027-06-15','amount'=>40000,'cheque_no'=>'000303','bank'=>'Emirates NBD'],['due_date'=>'2027-09-15','amount'=>40000,'cheque_no'=>'000304','bank'=>'Emirates NBD']],
 '__move_in'=>null,
];
$gen = new ContractPDFGenerator($conn, 2); $rc = new ReflectionClass($gen);
foreach (['replacePlaceholders','generateHeaderHTML','generateFooterHTML'] as $mm) $rc->getMethod($mm)->setAccessible(true);
$body = $rc->getMethod('replacePlaceholders')->invoke($gen, $html, $lease, ['id'=>0,'template_code'=>'tenancy_contract_v2']);
file_put_contents($argv[3], $body);
$mpdf = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4','margin_top'=>35,'margin_bottom'=>30,'margin_left'=>15,'margin_right'=>15,'tempDir'=>sys_get_temp_dir(),'default_font'=>'dejavusans','autoScriptToLang'=>true,'autoLangToFont'=>true]);
$mpdf->SetDirectionality('auto');
$mpdf->SetHTMLHeader($rc->getMethod('generateHeaderHTML')->invoke($gen, $lease));
$mpdf->SetHTMLFooter($rc->getMethod('generateFooterHTML')->invoke($gen));
$mpdf->WriteHTML($body);
$mpdf->Output($argv[2], 'F'); echo count($mpdf->pages), " pages\n";
