<?php
// hr/payroll_run_export_csv.php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/company_helper.php';
require_once __DIR__.'/includes/hr_payroll_company_access.php';
require_once __DIR__.'/includes/hr_payroll_accounting.php';
require_once __DIR__.'/includes/hr_payroll_cashback.php';
require_role(['Owner','Admin','HR'], $conn);

$run_id = (int)($_GET['id'] ?? 0);
if (!$run_id) { http_response_code(400); exit('Missing run id'); }

$run=$conn->prepare("SELECT * FROM payroll_runs WHERE id=?");
$run->execute([$run_id]); $run=$run->fetch(PDO::FETCH_ASSOC);
if(!$run){ http_response_code(404); exit('Run not found'); }
hr_payroll_require_run_access($conn, $run);

$payrollType = (($run['payroll_type'] ?? 'wps') === 'cash') ? 'cash' : 'wps';
// Cashback columns only while the run's toggle is on. Net stays the WPS net.
$cashbackOn = hr_payroll_cashback_on($conn, $run);

$rows=$conn->prepare("
  SELECT e.employee_code, e.full_name, COALESCE(e.payment_type, 'wps') AS payment_type,
         pi.base_pay, pi.allowance, pi.bonus,
         pi.adv_applied, pi.other_applied, pi.deductions, pi.net_pay, pi.notes" . ($cashbackOn ? ", pi.cashback" : "") . "
  FROM payroll_items pi
  JOIN employees e ON e.id=pi.employee_id
  WHERE pi.payroll_run_id=?
  ORDER BY e.full_name
");
$rows->execute([$run_id]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="payroll_run_'.$run_id.'.csv"');

$out = fopen('php://output', 'w');
$head = ['Payroll Type','Employee Code','Name','Payment Type','Base','Allowance','Bonus/OT','Cash Adv Applied','Other Ded Applied','Total Deductions','Net','Notes'];
if ($cashbackOn) {
  array_splice($head, 11, 0, ['Cashback (not in WPS)', 'Net after cashback']);
}
fputcsv($out, $head);
while($r=$rows->fetch(PDO::FETCH_ASSOC)){
  $line = [
    hr_payroll_run_type_label($payrollType),
    $r['employee_code'], $r['full_name'], hr_payroll_payment_type_label($r['payment_type']),
    number_format($r['base_pay'],2,'.',''),
    number_format($r['allowance'],2,'.',''),
    number_format($r['bonus'],2,'.',''),
    number_format($r['adv_applied']??0,2,'.',''),
    number_format($r['other_applied']??0,2,'.',''),
    number_format($r['deductions'],2,'.',''),
    number_format($r['net_pay'],2,'.',''),
    $r['notes']
  ];
  if ($cashbackOn) {
    $cb = (float)($r['cashback'] ?? 0);
    array_splice($line, 11, 0, [number_format($cb,2,'.',''), number_format((float)$r['net_pay'] - $cb,2,'.','')]);
  }
  fputcsv($out, $line);
}
fclose($out);
